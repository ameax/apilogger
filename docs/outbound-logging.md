# Outbound API Logging Documentation

## Overview

The outbound API logging feature allows you to track and monitor all external API calls made by your Laravel application. This is essential for debugging, performance monitoring, compliance, and understanding your application's external dependencies.

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Service Registry](#service-registry)
- [Filtering](#filtering)
- [Correlation IDs](#correlation-ids)
- [Retry Tracking](#retry-tracking)
- [Performance Monitoring](#performance-monitoring)
- [Troubleshooting](#troubleshooting)

## Installation

### Prerequisites

Outbound logging requires Guzzle HTTP client. If not already installed:

```bash
composer require guzzlehttp/guzzle
```

### Enabling Outbound Logging

1. Enable the feature in your configuration:

```php
// config/apilogger.php
'features' => [
    'outbound' => [
        'enabled' => env('API_LOGGER_OUTBOUND_ENABLED', true),
    ],
],
```

2. Ensure database migrations are run (adds required columns):

```bash
php artisan migrate
```

## Configuration

### Full Configuration Reference

```php
// config/apilogger.php
'features' => [
    'outbound' => [
        'enabled' => true,
        'level' => 'full', // none, basic, detailed, full

        // Filtering options
        'filters' => [
            'include_hosts' => ['*.api.example.com'],
            'exclude_hosts' => ['localhost', '127.0.0.1'],
            'include_services' => ['App\Services\PaymentService'],
            'exclude_services' => [],
            'include_methods' => [], // Empty means all
            'exclude_methods' => ['OPTIONS'],
            'min_response_time' => 0,
            'max_response_time' => null,
            'status_codes' => [], // Empty means all
            'always_log_errors' => true,
        ],

        // Correlation settings
        'correlation' => [
            'enabled' => true,
            'headers' => ['X-Correlation-ID', 'X-Request-ID'],
        ],

        // Retry tracking
        'retry' => [
            'track_retries' => true,
            'max_attempts' => 3,
        ],

        // Performance settings
        'performance' => [
            'track_dns_time' => true,
            'track_connect_time' => true,
            'track_ssl_time' => true,
            'slow_threshold_ms' => 5000,
        ],
    ],
],
```

### Logging Levels Explained

- **none**: No logging
- **basic**: Method, URL, status code, response time
- **detailed**: Includes headers, query parameters
- **full**: Complete request/response bodies

## Basic Usage

### Method 1: Using GuzzleHandlerStackFactory

```php
use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;
use GuzzleHttp\Client;

// Create a Guzzle client with automatic logging
$stack = GuzzleHandlerStackFactory::create();
$client = new Client([
    'handler' => $stack,
    'base_uri' => 'https://api.example.com',
]);

// All requests will be logged
$response = $client->get('/users');
```

### Method 2: Adding Middleware to Existing Stack

```php
use Ameax\ApiLogger\Outbound\GuzzleLoggerMiddleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Client;

$stack = HandlerStack::create();
$stack->push(GuzzleLoggerMiddleware::create(), 'api_logger');

$client = new Client(['handler' => $stack]);
```

### Method 3: Laravel HTTP Client

```php
use Illuminate\Support\Facades\Http;
use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;

$response = Http::withOptions([
    'handler' => GuzzleHandlerStackFactory::create(),
])->get('https://api.example.com/users');
```

## Service Registry

The Service Registry allows you to configure logging per service with custom settings.

### Registering a Service

```php
use Ameax\ApiLogger\Outbound\ServiceRegistry;

// In a service provider or bootstrap file
ServiceRegistry::register('App\Services\StripeService', [
    'enabled' => true,
    'name' => 'Stripe Payment API',
    'log_level' => 'full',
    'hosts' => ['api.stripe.com', 'files.stripe.com'],
    'always_log_errors' => true,
    'slow_threshold_ms' => 3000,
    'filters' => [
        'exclude_endpoints' => ['/v1/events'], // Don't log webhook checks
    ],
]);
```

### Using Service-Specific Configuration

```php
use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;

// Create a client with service-specific settings
$stack = GuzzleHandlerStackFactory::createForService('App\Services\StripeService');
$client = new Client(['handler' => $stack]);
```

### Multiple Services Example

```php
// Register multiple services with different configurations
ServiceRegistry::register('App\Services\PaymentService', [
    'name' => 'Payment Gateway',
    'log_level' => 'full',
    'hosts' => ['api.stripe.com', 'api.paypal.com'],
]);

ServiceRegistry::register('App\Services\WeatherService', [
    'name' => 'Weather API',
    'log_level' => 'basic', // Less detailed logging
    'hosts' => ['api.openweathermap.org'],
]);

ServiceRegistry::register('App\Services\InternalApiService', [
    'enabled' => false, // Disable logging for internal APIs
]);
```

## Filtering

### Host-Based Filtering

```php
// config/apilogger.php
'filters' => [
    // Use wildcards to match multiple subdomains
    'include_hosts' => [
        '*.stripe.com',
        'api.paypal.com',
        '*.amazonaws.com',
    ],

    // Never log these hosts
    'exclude_hosts' => [
        'localhost',
        '127.0.0.1',
        '*.test',
    ],
],
```

### Service-Based Filtering

```php
'filters' => [
    // Only log these services
    'include_services' => [
        'App\Services\PaymentService',
        'App\Services\ShippingService',
    ],

    // Or exclude specific services
    'exclude_services' => [
        'App\Services\CacheService',
    ],
],
```

### Custom Filtering Logic

```php
use Ameax\ApiLogger\Outbound\OutboundFilterService;

// In a service provider
$filter = app(OutboundFilterService::class);

$filter->addCustomFilter(function ($request, $options) {
    // Don't log requests to sandbox environments
    if (str_contains($request->getUri()->getHost(), 'sandbox')) {
        return false;
    }

    // Always log production payment endpoints
    if (str_contains($request->getUri()->getPath(), '/payments')) {
        return true;
    }

    return null; // Continue with default filters
});
```

## Correlation IDs

Correlation IDs link related requests across your application, making it easier to trace request flows.

### Automatic Correlation

```php
use Ameax\ApiLogger\Support\CorrelationIdManager;

// The manager automatically:
// 1. Extracts correlation ID from incoming request headers
// 2. Generates one if not present
// 3. Propagates to outbound requests

$correlationManager = app(CorrelationIdManager::class);
$correlationId = $correlationManager->getCorrelationId();
```

### Manual Correlation

```php
// Set a specific correlation ID
$correlationManager->setCorrelationId('custom-correlation-id');

// Add to Guzzle request
$client->get('/api/endpoint', [
    'headers' => [
        'X-Correlation-ID' => $correlationManager->getCorrelationId(),
    ],
]);
```

### Tracing Request Chains

```php
use Ameax\ApiLogger\Models\ApiLog;

// Find all requests in a correlation chain
$correlationId = 'abc-123-def';
$requestChain = ApiLog::withCorrelation($correlationId)
    ->orderBy('created_at')
    ->get();

// Analyze the chain
foreach ($requestChain as $log) {
    echo "{$log->direction}: {$log->method} {$log->url}\n";
    echo "Service: {$log->service}\n";
    echo "Response: {$log->response_code} in {$log->response_time}ms\n\n";
}
```

## Retry Tracking

Track retry attempts and analyze retry patterns.

### Automatic Retry Detection

The package automatically detects and tracks retries when using Guzzle's retry middleware:

```php
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;

$stack = GuzzleHandlerStackFactory::create();

// Add retry middleware
$stack->push(Middleware::retry(function ($retries, Request $request, Response $response = null, $exception = null) {
    // Retry on connection errors
    if ($exception instanceof ConnectException) {
        return $retries < 3;
    }

    // Retry on 5xx errors
    if ($response && $response->getStatusCode() >= 500) {
        return $retries < 3;
    }

    return false;
}));

$client = new Client(['handler' => $stack]);
```

### Analyzing Retry Patterns

```php
use Ameax\ApiLogger\Models\ApiLog;

// Find all retry attempts for a service
$retries = ApiLog::outbound()
    ->forService('PaymentAPI')
    ->where('retry_attempt', '>', 0)
    ->get();

// Calculate retry success rate
$totalRetries = $retries->count();
$successfulRetries = $retries->where('response_code', '<', 400)->count();
$retrySuccessRate = ($successfulRetries / $totalRetries) * 100;

// Find endpoints that frequently require retries
$problematicEndpoints = ApiLog::outbound()
    ->select('url')
    ->selectRaw('MAX(retry_attempt) as max_retries')
    ->selectRaw('COUNT(*) as total_requests')
    ->where('retry_attempt', '>', 0)
    ->groupBy('url')
    ->orderByDesc('max_retries')
    ->get();
```

## Performance Monitoring

### Using the Monitoring Service

```php
use Ameax\ApiLogger\Services\MonitoringService;

$monitor = app(MonitoringService::class);

// Check service health
$health = $monitor->checkServiceHealth('StripeAPI', now()->subHours(24), now());
// Returns: ['healthy' => true/false, 'error_rate' => 0.05, 'avg_response_time' => 250]

// Get detailed metrics
$metrics = $monitor->getServiceMetrics('StripeAPI', now()->subDays(7), now());
// Returns comprehensive metrics including percentiles, error rates, etc.

// Find anomalies
$anomalies = $monitor->detectAnomalies('StripeAPI', now()->subHours(1), now());
// Returns endpoints with unusual behavior

// Check for slow endpoints
$slowEndpoints = $monitor->getSlowEndpoints(5000, now()->subHours(24), now());
// Returns endpoints slower than 5 seconds
```

### Performance Alerts

```php
// Set up performance alerts in a scheduled command
namespace App\Console\Commands;

use Ameax\ApiLogger\Services\MonitoringService;
use Illuminate\Console\Command;

class MonitorApiPerformance extends Command
{
    protected $signature = 'monitor:api-performance';

    public function handle(MonitoringService $monitor)
    {
        $services = ['StripeAPI', 'PayPalAPI', 'ShippingAPI'];

        foreach ($services as $service) {
            $health = $monitor->checkServiceHealth($service, now()->subHour(), now());

            if (!$health['healthy']) {
                // Send alert
                $this->alert("Service {$service} is unhealthy!");
                $this->line("Error rate: {$health['error_rate']}%");
                $this->line("Avg response time: {$health['avg_response_time']}ms");
            }

            // Check for slow response times
            if ($health['avg_response_time'] > 3000) {
                $this->warn("Service {$service} is responding slowly");
            }
        }
    }
}
```

## Troubleshooting

### Logs Not Being Created

1. **Check if outbound logging is enabled:**
```php
config('apilogger.features.outbound.enabled'); // Should be true
```

2. **Verify Guzzle middleware is attached:**
```php
// Check handler stack
$stack = $client->getConfig('handler');
// Should contain 'api_logger' middleware
```

3. **Check filtering rules:**
```php
// Ensure your hosts/services aren't excluded
config('apilogger.features.outbound.filters');
```

### Performance Issues

1. **Use appropriate logging level:**
```php
// Use 'basic' for high-volume APIs
'level' => 'basic',
```

2. **Enable queue processing:**
```php
'performance' => [
    'use_queue' => true,
],
```

3. **Filter unnecessary requests:**
```php
'filters' => [
    'min_response_time' => 100, // Only log slow requests
],
```

### Memory Issues with Large Responses

1. **Limit response body logging:**
```php
// Use 'detailed' instead of 'full' for large responses
'level' => 'detailed',
```

2. **Implement custom sanitization:**
```php
use Ameax\ApiLogger\Services\DataSanitizer;

$sanitizer = app(DataSanitizer::class);
$sanitizer->addCustomSanitizer(function ($data) {
    // Truncate large fields
    if (isset($data['large_field']) && strlen($data['large_field']) > 1000) {
        $data['large_field'] = substr($data['large_field'], 0, 1000) . '...';
    }
    return $data;
});
```

### Correlation ID Not Propagating

1. **Check header configuration:**
```php
'correlation' => [
    'enabled' => true,
    'headers' => ['X-Correlation-ID', 'X-Request-ID'],
],
```

2. **Ensure middleware order:**
```php
// Correlation middleware should be early in the stack
$stack->push(GuzzleLoggerMiddleware::create(), 'api_logger');
```

## Best Practices

1. **Use Service Registry for all external services** - Centralized configuration makes maintenance easier

2. **Set appropriate logging levels** - Use 'full' for payment APIs, 'basic' for high-volume services

3. **Implement retry logic with tracking** - Helps identify unreliable endpoints

4. **Use correlation IDs consistently** - Essential for debugging distributed systems

5. **Monitor performance metrics regularly** - Set up alerts for anomalies

6. **Filter sensitive data** - Never log credit cards, passwords, or API keys

7. **Use queue processing for production** - Reduces impact on response times

8. **Implement circuit breakers** - Prevent cascading failures when external services are down

9. **Regular cleanup** - Use retention policies to manage database size

10. **Test in staging first** - Ensure filtering and sanitization work correctly before production