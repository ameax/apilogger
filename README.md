# API Logger for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ameax/apilogger.svg?style=flat-square)](https://packagist.org/packages/ameax/apilogger)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/ameax/apilogger/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ameax/apilogger/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/ameax/apilogger/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/ameax/apilogger/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/ameax/apilogger.svg?style=flat-square)](https://packagist.org/packages/ameax/apilogger)

A powerful, flexible, and performant API request/response logging package for Laravel applications. Track API usage, debug issues, monitor performance, and maintain compliance with ease.

## Features

- 🚀 **Multiple Storage Backends**: Database, JSON Lines, or custom drivers
- 🔒 **Privacy-First**: Automatic sanitization of sensitive data
- ⚡ **High Performance**: Queue support, batch operations, circuit breaker pattern
- 🎯 **Smart Filtering**: Log only what matters with flexible filters
- 📊 **Rich Insights**: Track response times, error rates, usage patterns
- 🧹 **Auto-Cleanup**: Configurable retention policies with different durations for errors
- 🔄 **Fallback Support**: Multiple storage drivers with automatic failover
- 🎨 **Highly Configurable**: Extensive configuration options for every use case
- 🔗 **Outbound API Logging**: Track external API calls with Guzzle middleware
- 🔍 **Correlation IDs**: Link related requests across inbound and outbound calls
- 🏢 **Service Registry**: Manage and configure multiple external services
- 📤 **HAR & HTML Export**: Export logs in industry-standard HAR format or standalone HTML

## Requirements

- PHP 8.2 or higher
- Laravel 11.x or 12.x

## Installation

Install the package via Composer:

```bash
composer require ameax/apilogger
```

### Database Setup

If using database storage (default), publish and run the migrations:

```bash
php artisan vendor:publish --tag="apilogger-migrations"
php artisan migrate
```

### Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag="apilogger-config"
```

This will create `config/apilogger.php` with extensive configuration options.

## Quick Start

### Basic Usage

The package automatically logs API requests once installed. Add the middleware to your API routes:

```php
// In routes/api.php or your route service provider
Route::middleware(['api', \Ameax\ApiLogger\Middleware\LogApiRequests::class])
    ->group(function () {
        // Your API routes
    });
```

Or add it globally in your HTTP Kernel:

```php
// In app/Http/Kernel.php
protected $middlewareGroups = [
    'api' => [
        // Other middleware...
        \Ameax\ApiLogger\Middleware\LogApiRequests::class,
    ],
];
```

### Configuration Options

```php
// config/apilogger.php
return [
    'enabled' => env('API_LOGGER_ENABLED', true),

    // Logging level: none, basic, detailed, full
    'level' => env('API_LOGGER_LEVEL', 'detailed'),

    // Storage configuration
    'storage' => [
        'driver' => env('API_LOGGER_DRIVER', 'database'),

        // Database storage options
        'database' => [
            'connection' => null, // Uses default connection
            'table' => 'api_logs',
        ],

        // JSON Lines storage options
        'jsonline' => [
            'path' => storage_path('logs/api'),
            'daily_rotation' => true,
            'compress_old_files' => true,
        ],
    ],

    // Privacy settings
    'privacy' => [
        'exclude_fields' => ['password', 'token', 'secret'],
        'exclude_headers' => ['Authorization', 'Cookie'],
        'masking_strategy' => 'partial', // full, partial, or hash
    ],

    // Performance settings
    'performance' => [
        'use_queue' => false,
        'queue_name' => 'default',
        'batch_size' => 100,
        'timeout' => 1000, // milliseconds
    ],

    // Filter settings
    'filters' => [
        'min_response_time' => 0, // Log all requests
        'exclude_routes' => ['/health', '/metrics'],
        'exclude_methods' => ['OPTIONS'],
        'exclude_status_codes' => [],
        'always_log_errors' => true,
    ],

    // Retention settings
    'retention' => [
        'days' => 30, // Keep normal logs for 30 days
        'error_days' => 90, // Keep error logs for 90 days
    ],
];
```

## Usage Examples

### Accessing Logs

```php
use Ameax\ApiLogger\Models\ApiLog;

// Get recent API logs
$logs = ApiLog::latest()->take(100)->get();

// Find logs for a specific user
$userLogs = ApiLog::forUser('user-123')->get();

// Get error logs
$errors = ApiLog::errors()->get();

// Get slow requests
$slowRequests = ApiLog::slowRequests(1000)->get(); // > 1 second

// Get logs for specific endpoint
$endpointLogs = ApiLog::forEndpoint('/api/users')->get();

// Get logs within date range
$logs = ApiLog::betweenDates('2024-01-01', '2024-01-31')->get();
```

### Using Different Storage Drivers

#### Database Storage (Default)

```php
// config/apilogger.php
'storage' => [
    'driver' => 'database',
    'database' => [
        'connection' => null, // Uses default
        'table' => 'api_logs',
    ],
],
```

#### JSON Lines Storage

```php
// config/apilogger.php
'storage' => [
    'driver' => 'jsonline',
    'jsonline' => [
        'path' => storage_path('logs/api'),
        'daily_rotation' => true,
    ],
],
```

#### Fallback Storage (Multiple Drivers)

```php
// config/apilogger.php
'storage' => [
    'driver' => 'fallback',
    'fallback' => [
        'drivers' => ['database', 'jsonline'],
    ],
],
```

### Custom Filtering

```php
use Ameax\ApiLogger\Facades\ApiLogger;

// Add custom filter in a service provider
ApiLogger::filter(function ($request, $response, $responseTime) {
    // Log only if custom condition is met
    return $request->user()->isAdmin();
});
```

### Queue Support

Enable queue processing for better performance:

```php
// config/apilogger.php
'performance' => [
    'use_queue' => true,
    'queue_name' => 'api-logs',
],
```

Don't forget to run your queue workers:

```bash
php artisan queue:work --queue=api-logs
```

### Data Sanitization

Customize sensitive field handling:

```php
use Ameax\ApiLogger\Services\DataSanitizer;

// In a service provider
$sanitizer = app(DataSanitizer::class);

// Add custom fields to exclude
$sanitizer->addExcludeFields(['credit_card', 'ssn']);

// Add custom headers to exclude
$sanitizer->addExcludeHeaders(['X-API-Key', 'X-Secret']);

// Add fields to mask (partial display)
$sanitizer->addMaskFields(['email', 'phone']);
```

### Outbound API Logging

Track external API calls made by your application using Guzzle:

```php
use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;
use Ameax\ApiLogger\Outbound\ServiceRegistry;
use GuzzleHttp\Client;

// Register a service for automatic logging
ServiceRegistry::register('App\Services\StripeService', [
    'enabled' => true,
    'name' => 'Stripe API',
    'log_level' => 'full',
    'hosts' => ['api.stripe.com'],
    'always_log_errors' => true,
]);

// Create a Guzzle client with logging middleware
$stack = GuzzleHandlerStackFactory::createForService('App\Services\StripeService');
$client = new Client([
    'handler' => $stack,
    'base_uri' => 'https://api.stripe.com',
]);

// All requests made with this client will be logged automatically
$response = $client->get('/v1/customers');
```

#### Correlation ID Support

Link related requests across your application:

```php
use Ameax\ApiLogger\Support\CorrelationIdManager;

// In your middleware or service provider
$correlationManager = app(CorrelationIdManager::class);

// Will extract from incoming request or generate new one
$correlationId = $correlationManager->getCorrelationId();

// Automatically propagated to outbound requests
$client->post('/api/endpoint', [
    'correlation_id' => $correlationId,
]);
```

#### Service Filtering

Configure which external services to log:

```php
// config/apilogger.php
'features' => [
    'outbound' => [
        'enabled' => true,
        'filters' => [
            'include_hosts' => ['*.stripe.com', 'api.paypal.com'],
            'exclude_hosts' => ['localhost', '127.0.0.1'],
            'include_services' => ['App\Services\PaymentService'],
            'always_log_errors' => true,
        ],
    ],
],
```

## Exporting API Logs

The package includes powerful export functionality for sharing API logs with external partners, debugging in professional tools, or archiving logs in standardized formats.

### Export Formats

#### HAR (HTTP Archive) Format

Export logs in the [W3C HAR 1.2 specification](http://www.softwareishard.com/blog/har-12-spec/) format, which is compatible with industry-standard tools:

```php
use Ameax\ApiLogger\Models\ApiLog;
use Ameax\ApiLogger\Services\Export\HarExportService;

$log = ApiLog::find(123);
$harExport = app(HarExportService::class);

// Generate HAR format
$har = $harExport->generate($log);

// Save to file
file_put_contents('api-log.har', json_encode($har, JSON_PRETTY_PRINT));

// Or return as download response
return response()->json($har)
    ->header('Content-Type', 'application/json')
    ->header('Content-Disposition', 'attachment; filename="api-log.har"');
```

**HAR files can be opened in:**
- **Chrome DevTools** (Network tab → Import HAR file)
- **Firefox Developer Tools** (Network Monitor → Import)
- **Postman** (Import → Raw text)
- **Insomnia** (Import Data → From File)
- **Charles Proxy**, **Fiddler**, and other HTTP debugging tools
- Online viewers like [HAR Viewer](http://www.softwareishard.com/har/viewer/)

**Use Cases:**
- Share API request/response details with external API providers for debugging
- Analyze request/response timing and performance
- Document API behavior with exact request/response examples
- Create test fixtures from real API interactions
- Archive API logs in a standardized, tool-independent format

**HAR Format Features:**
- Complete request details (method, URL, headers, body)
- Full response information (status, headers, body, timing)
- Custom fields for API Logger metadata (correlation ID, service name, retry attempts)
- Standard format ensures compatibility across tools
- JSON-based, human-readable structure

#### HTML Export

Export logs as standalone HTML files with inline CSS for easy viewing and sharing:

```php
use Ameax\ApiLogger\Models\ApiLog;
use Ameax\ApiLogger\Services\Export\HtmlExportService;

$log = ApiLog::find(123);
$htmlExport = app(HtmlExportService::class);

// Generate HTML
$html = $htmlExport->generate($log);

// Save to file
file_put_contents('api-log.html', $html);

// Or return as download response
return response($html)
    ->header('Content-Type', 'text/html')
    ->header('Content-Disposition', 'attachment; filename="api-log.html"');
```

**HTML Export Features:**
- Standalone HTML file with inline CSS (no external dependencies)
- Syntax-highlighted JSON for request/response bodies
- Color-coded status badges and response times
- Responsive design for viewing on any device
- Professional appearance suitable for client communication
- Print-friendly layout

**Use Cases:**
- Share API logs via email with non-technical stakeholders
- Create printable API documentation
- Archive logs in a human-readable format
- Quick visual inspection without opening specialized tools
- Embed in reports or documentation

### Export Service Architecture

Both export services extend a common base class for code reuse:

```php
namespace Ameax\ApiLogger\Services\Export;

// Base class with shared methods
abstract class ApiLogExportService
{
    protected function buildHeaders(?array $headers): array;
    protected function buildQueryString(?array $parameters): array;
    protected function encodeBody(?array $body): string;
    protected function getMimeType(?array $headers, string $default): string;
    protected function getStatusText(int $code): string;
}

// HAR export implementation
class HarExportService extends ApiLogExportService
{
    public function generate(ApiLog $apiLog): array;
}

// HTML export implementation
class HtmlExportService extends ApiLogExportService
{
    public function generate(ApiLog $apiLog): string;
}
```

### Example: Bulk Export

Export multiple logs at once:

```php
use Ameax\ApiLogger\Models\ApiLog;
use Ameax\ApiLogger\Services\Export\HarExportService;

$logs = ApiLog::where('service', 'Stripe API')
    ->where('response_code', '>=', 400)
    ->get();

$harExport = app(HarExportService::class);

// Create HAR file with multiple entries
$har = [
    'log' => [
        'version' => '1.2',
        'creator' => [
            'name' => 'API Logger',
            'version' => '1.0',
        ],
        'entries' => $logs->map(fn($log) =>
            $harExport->generate($log)['log']['entries'][0]
        )->all(),
    ],
];

file_put_contents('stripe-errors.har', json_encode($har, JSON_PRETTY_PRINT));
```

### Integration with UI Frameworks

The export services are framework-agnostic and can be easily integrated into any UI:

**Filament Example:**
```php
use Filament\Actions\Action;
use Ameax\ApiLogger\Services\Export\HarExportService;

Action::make('export_har')
    ->label('Export HAR')
    ->icon('heroicon-o-arrow-down-tray')
    ->action(function (ApiLog $record) {
        $harExport = app(HarExportService::class);
        $har = $harExport->generate($record);

        return response()->streamDownload(
            fn () => print(json_encode($har, JSON_PRETTY_PRINT)),
            sprintf('api-log-%s.har', $record->id),
            ['Content-Type' => 'application/json']
        );
    });
```

**Laravel Livewire Example:**
```php
use Livewire\Component;
use Ameax\ApiLogger\Services\Export\HtmlExportService;

class ApiLogViewer extends Component
{
    public function downloadHtml($logId)
    {
        $log = ApiLog::findOrFail($logId);
        $htmlExport = app(HtmlExportService::class);
        $html = $htmlExport->generate($log);

        return response()->streamDownload(
            fn () => print($html),
            "api-log-{$logId}.html"
        );
    }
}
```

## Maintenance Commands

### Clean Old Logs

```bash
# Clean logs older than configured retention period
php artisan api-logger:clean

# Clean logs older than specific days
php artisan api-logger:clean --days=60

# Clean with different retention for errors
php artisan api-logger:clean --days=30 --error-days=90
```

### Export Logs

```bash
# Export logs to JSON
php artisan api-logger:export --format=json --output=logs.json

# Export logs for specific date range
php artisan api-logger:export --from="2024-01-01" --to="2024-01-31"

# Export only errors
php artisan api-logger:export --errors-only
```

## Performance Considerations

### Circuit Breaker

The package includes a circuit breaker pattern to prevent cascading failures. If storage fails 5 times consecutively, it temporarily stops attempting to store logs.

### Batch Operations

When storing multiple logs, use batch operations:

```php
use Ameax\ApiLogger\StorageManager;

$storage = app(StorageManager::class)->driver();
$storage->storeBatch($logEntries); // Automatically chunks large batches
```

### Response Time Filtering

Reduce storage overhead by only logging slow requests:

```php
// config/apilogger.php
'filters' => [
    'min_response_time' => 100, // Only log requests taking > 100ms
],
```

## Advanced Usage

### Custom Storage Driver

Create a custom storage driver by implementing `StorageInterface`:

```php
use Ameax\ApiLogger\Contracts\StorageInterface;

class CustomStorage implements StorageInterface
{
    public function store(LogEntry $logEntry): bool
    {
        // Your implementation
    }

    public function retrieve(array $criteria = [], int $limit = 100, int $offset = 0): Collection
    {
        // Your implementation
    }

    // Other required methods...
}

// Register in a service provider
use Ameax\ApiLogger\Facades\ApiLogger;

ApiLogger::extend('custom', function ($app, $config) {
    return new CustomStorage($config);
});
```

### Request Enrichment

Add custom data to logs:

```php
use Ameax\ApiLogger\Services\RequestCapture;

// In a service provider
$capture = app(RequestCapture::class);

$capture->enrich(function ($request) {
    return [
        'custom_field' => 'custom_value',
        'request_source' => $request->header('X-Request-Source'),
    ];
});
```

### Monitoring Integration

Get storage statistics:

```php
use Ameax\ApiLogger\StorageManager;

$storage = app(StorageManager::class)->driver();
$stats = $storage->getStatistics();

// Returns:
// [
//     'total_logs' => 10000,
//     'total_errors' => 500,
//     'avg_response_time' => 150.5,
//     'status_groups' => ['2xx' => 8000, '4xx' => 1500, '5xx' => 500],
//     ...
// ]
```

## Testing

Run the test suite:

```bash
composer test
```

Run with code coverage:

```bash
composer test-coverage
```

## Troubleshooting

### Logs Not Being Created

1. Check if logging is enabled:
   ```php
   config('apilogger.enabled') // Should be true
   ```

2. Verify the logging level:
   ```php
   config('apilogger.level') // Should not be 'none'
   ```

3. Check filters aren't excluding your requests:
   ```php
   config('apilogger.filters')
   ```

### Performance Issues

1. Enable queue processing
2. Increase batch size for bulk operations
3. Use response time filtering
4. Consider using JSON Lines storage for high-volume APIs

### Storage Errors

1. Check database connection and migrations
2. Verify file permissions for JSON Lines storage
3. Enable fallback storage for redundancy
4. Monitor circuit breaker status in logs

## Security

If you discover any security-related issues, please email security@ameax.com instead of using the issue tracker.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Ameax](https://github.com/ameax)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.