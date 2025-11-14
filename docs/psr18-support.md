# PSR-18 HTTP Client Support

ApiLogger now supports logging for any PSR-18 compliant HTTP client, including libraries that use HTTPlug/HttpMethodsClient.

## Overview

The PSR-18 support allows you to log HTTP requests and responses from:
- Pure PSR-18 clients (e.g., Symfony HttpClient, Guzzle 7+)
- HTTPlug-based clients (e.g., Typesense PHP SDK)
- Any library using `HttpMethodsClient`

## Architecture

```
Your Application
    ↓
PSR-18/HTTPlug Client
    ↓
Psr18LoggerMiddleware (Logging Layer)
    ↓
Base HTTP Client
    ↓
External API
```

The `Psr18LoggerMiddleware` implements both `ClientInterface` (PSR-18) and `HttpClient` (HTTPlug), making it compatible with a wide range of HTTP clients.

## Components

### Psr18LoggerMiddleware

Located at `src/Outbound/Psr18LoggerMiddleware.php`

**Key Features:**
- Implements `Psr\Http\Client\ClientInterface` (PSR-18)
- Implements `Http\Client\HttpClient` (HTTPlug)
- Supports both `sendRequest()` and `send()` methods
- Automatic request/response logging
- Correlation ID propagation
- Service-based filtering
- Error handling with exception logging

**Interfaces Implemented:**
```php
class Psr18LoggerMiddleware implements ClientInterface, HttpClient
{
    public function sendRequest(RequestInterface $request): ResponseInterface;
    public function send(string $method, $uri, array $headers = [], $body = null): ResponseInterface;
}
```

### Psr18ClientFactory

Located at `src/Outbound/Psr18ClientFactory.php`

**Purpose:** Simplifies the creation of logging-enabled PSR-18 clients.

**Usage:**
```php
$factory = app(Psr18ClientFactory::class);
$loggingClient = $factory->create($baseClient, [
    'service_class' => YourService::class,
    'service_name' => 'Your Service',
]);
```

## Configuration

### Enable PSR-18 Support

In `config/apilogger.php`:

```php
'features' => [
    'outbound' => [
        'enabled' => env('API_LOGGER_OUTBOUND_ENABLED', true),

        // Enable PSR-18 client logging
        'psr18' => [
            'enabled' => env('API_LOGGER_PSR18_ENABLED', true),
        ],

        'filters' => [
            // Include specific services
            'include_services' => [
                'App\Services\YourService',
            ],

            // Include specific hosts
            'include_hosts' => [
                'api.example.com',
            ],
        ],
    ],
],
```

### Environment Variables

```bash
# Enable outbound logging
API_LOGGER_OUTBOUND_ENABLED=true

# Enable PSR-18 support
API_LOGGER_PSR18_ENABLED=true
```

## Usage Examples

### Example 1: Basic PSR-18 Client

```php
use Ameax\ApiLogger\Outbound\Psr18ClientFactory;
use Http\Discovery\Psr18ClientDiscovery;

// Get base client
$baseClient = Psr18ClientDiscovery::find();

// Wrap with logging
$factory = app(Psr18ClientFactory::class);
$loggingClient = $factory->create($baseClient, [
    'service_class' => App\Services\MyApiService::class,
    'service_name' => 'My API Service',
]);

// Use the client - all requests will be logged
$response = $loggingClient->sendRequest($request);
```

### Example 2: Typesense Integration

For libraries like Typesense that use `HttpMethodsClient`, you need to wrap the logging client:

```php
use Ameax\ApiLogger\Outbound\Psr18ClientFactory;
use Http\Client\Common\HttpMethodsClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;

// 1. Get base PSR-18 client
$baseClient = Psr18ClientDiscovery::find();

// 2. Wrap with logging
$factory = app(Psr18ClientFactory::class);
$loggingClient = $factory->create($baseClient, [
    'service_class' => App\Services\TypesenseSearchService::class,
    'service_name' => 'Typesense Search',
]);

// 3. Wrap in HttpMethodsClient for Typesense compatibility
$httpMethodsClient = new HttpMethodsClient(
    $loggingClient,
    Psr17FactoryDiscovery::findRequestFactory(),
    Psr17FactoryDiscovery::findStreamFactory()
);

// 4. Pass to Typesense
$config = config('scout.typesense.client-settings');
$config['client'] = $httpMethodsClient;
$typesenseClient = new \Typesense\Client($config);
```

### Example 3: Service Provider Integration

Create a service provider to automatically inject logging into your HTTP clients:

```php
<?php

namespace App\Providers;

use Ameax\ApiLogger\Outbound\Psr18ClientFactory;
use Ameax\ApiLogger\Outbound\ServiceRegistry;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Support\ServiceProvider;

class YourServiceLoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register service in ApiLogger
        if ($this->isLoggingEnabled()) {
            ServiceRegistry::register(
                serviceClass: \App\Services\YourService::class,
                config: [
                    'enabled' => true,
                    'log_level' => 'detailed',
                    'name' => 'Your Service Name',
                    'hosts' => [
                        env('YOUR_SERVICE_HOST', 'api.example.com'),
                    ],
                ],
                metadata: [
                    'name' => 'Your Service',
                    'description' => 'Service description',
                ]
            );

            // Create custom client with logging
            $this->app->singleton('your.service.client', function ($app) {
                $baseClient = Psr18ClientDiscovery::find();

                $factory = $app->make(Psr18ClientFactory::class);
                return $factory->create($baseClient, [
                    'service_class' => \App\Services\YourService::class,
                    'service_name' => 'Your Service',
                ]);
            });
        }
    }

    private function isLoggingEnabled(): bool
    {
        return config('apilogger.enabled', false)
            && config('apilogger.features.outbound.enabled', false)
            && config('apilogger.features.outbound.psr18.enabled', true);
    }
}
```

Then register the provider in `bootstrap/providers.php`:

```php
return [
    // ...
    App\Providers\YourServiceLoggingServiceProvider::class,
];
```

## Service Registry

Register your service for better metadata and filtering:

```php
use Ameax\ApiLogger\Outbound\ServiceRegistry;

ServiceRegistry::register(
    serviceClass: \App\Services\MyService::class,
    config: [
        'enabled' => true,
        'log_level' => 'full', // 'basic', 'detailed', 'full'
        'name' => 'My Service',
        'hosts' => ['api.example.com'],
        'always_log_errors' => true,
    ],
    metadata: [
        'name' => 'My Service',
        'description' => 'Service for interacting with Example API',
    ]
);
```

## Logged Information

For each HTTP request, the following information is logged:

- **Request Details:**
  - HTTP method (GET, POST, etc.)
  - Full endpoint URL
  - Request headers (sanitized)
  - Request body (sanitized)
  - Query parameters (sanitized)

- **Response Details:**
  - HTTP status code
  - Response headers (sanitized)
  - Response body (sanitized)
  - Response time in milliseconds

- **Metadata:**
  - Direction: `outbound`
  - Service name
  - Service class
  - Correlation ID
  - Environment
  - Timestamp

## Filtering

### Include Filters

Only log requests matching these criteria:

```php
'filters' => [
    'include_hosts' => [
        'api.example.com',
        '*.stripe.com',
    ],
    'include_services' => [
        'App\Services\PaymentService',
    ],
    'include_methods' => [
        'POST', 'PUT', 'DELETE',
    ],
],
```

### Exclude Filters

Exclude filters take precedence over include filters:

```php
'filters' => [
    'exclude_hosts' => [
        // Be careful: excluding 'localhost' will prevent logging
        // for services running locally (like Typesense)
    ],
    'exclude_patterns' => [
        '/health',
        '/metrics',
    ],
],
```

## Troubleshooting

### No Logs Are Created

**Check 1: Verify logging is enabled**
```php
config('apilogger.enabled'); // Should be true
config('apilogger.features.outbound.enabled'); // Should be true
config('apilogger.features.outbound.psr18.enabled'); // Should be true
```

**Check 2: Verify filters**
```php
// Make sure your service is included
config('apilogger.features.outbound.filters.include_services');

// Make sure host is not excluded
config('apilogger.features.outbound.filters.exclude_hosts');
```

**Check 3: Verify middleware is in the chain**
```php
// The client should be wrapped with Psr18LoggerMiddleware
$client = app('your.service.client');
var_dump($client instanceof Ameax\ApiLogger\Outbound\Psr18LoggerMiddleware);
```

### Type Errors with Factories

**Problem:** `Cannot assign Nyholm\Psr7\Factory\Psr17Factory to property ... of type Http\Message\RequestFactory`

**Solution:** Use PSR-17 interfaces, not HTTPlug-specific ones:
```php
// ❌ Wrong
private RequestFactory $requestFactory;

// ✅ Correct
private RequestFactoryInterface $requestFactory;
```

### HttpMethodsClient is Final

**Problem:** `Class cannot extend final class Http\Client\Common\HttpMethodsClient`

**Solution:** Don't extend `HttpMethodsClient`. Instead, wrap your logging client:
```php
$loggingClient = $factory->create($baseClient, [...]);

// Wrap in HttpMethodsClient
$httpMethodsClient = new HttpMethodsClient(
    $loggingClient,
    Psr17FactoryDiscovery::findRequestFactory(),
    Psr17FactoryDiscovery::findStreamFactory()
);
```

## Performance Considerations

- **Async Logging:** Set `use_queue => true` to process logs asynchronously
- **Body Size Limit:** Configure `max_body_size` to prevent logging huge payloads
- **Selective Logging:** Use filters to only log important requests

```php
'performance' => [
    'use_queue' => env('API_LOGGER_USE_QUEUE', true),
    'max_body_size' => env('API_LOGGER_MAX_BODY_SIZE', 65536), // 64KB
],
```

## Security & Privacy

The middleware automatically sanitizes sensitive data:

```php
'privacy' => [
    'exclude_fields' => [
        'password',
        'api_key',
        'secret',
        'token',
    ],
    'mask_fields' => [
        'email',
        'phone',
    ],
],
```

## Compatibility

- **PHP:** ^8.2
- **Laravel:** ^11.0
- **PSR-18:** Any compliant client
- **HTTPlug:** Compatible with HTTPlug-based libraries
- **Tested with:**
  - Typesense PHP SDK v5.1.0
  - Symfony HttpClient
  - Guzzle 7+

## See Also

- [Main README](../README.md)
- [Guzzle Support Documentation](guzzle-support.md)
- [Configuration Guide](configuration.md)
