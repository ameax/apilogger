# Migration Guide

## Upgrading to Version 2.0 (Outbound Logging Support)

This guide helps you upgrade from earlier versions of the API Logger package to version 2.0, which includes outbound API logging capabilities.

## What's New in 2.0

### Major Features
- **Outbound API Logging**: Track external API calls made by your application
- **Service Registry**: Configure logging per external service
- **Correlation IDs**: Link related requests across your application
- **Retry Tracking**: Monitor retry attempts and success rates
- **Enhanced Metadata**: Native database columns for better performance
- **Monitoring Service**: Built-in health checks and performance metrics

### Breaking Changes
- **None!** Version 2.0 is fully backward compatible with 1.x

### Database Changes
- New columns added to `api_logs` table (migration required)
- Removed `request_id` UUID column (now using auto-increment ID)
- Added native columns for better query performance

## Upgrade Steps

### 1. Update Package

```bash
composer update ameax/apilogger
```

### 2. Run New Migrations

```bash
php artisan migrate
```

This will add the following columns to your `api_logs` table:
- `direction` (enum: inbound/outbound)
- `service` (varchar: service name)
- `correlation_identifier` (varchar: correlation ID)
- `retry_attempt` (integer: retry count)

### 3. Update Configuration (Optional)

Publish the latest configuration if you want to customize outbound logging:

```bash
php artisan vendor:publish --tag="apilogger-config" --force
```

New configuration sections:
```php
'features' => [
    'inbound' => [
        'enabled' => true,
        // ... existing inbound config
    ],
    'outbound' => [
        'enabled' => false, // Disabled by default
        'level' => 'detailed',
        // ... outbound config
    ],
],
```

### 4. Clear Caches

```bash
php artisan config:clear
php artisan cache:clear
```

## Enabling Outbound Logging

### Quick Start

1. Enable in `.env`:
```env
API_LOGGER_OUTBOUND_ENABLED=true
```

2. Add to your Guzzle clients:
```php
use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;

$client = new \GuzzleHttp\Client([
    'handler' => GuzzleHandlerStackFactory::create(),
]);
```

### For Existing Guzzle Clients

If you have existing Guzzle clients, add the middleware:

```php
use Ameax\ApiLogger\Outbound\GuzzleLoggerMiddleware;

// Get existing handler stack
$stack = $client->getConfig('handler') ?? \GuzzleHttp\HandlerStack::create();

// Add logging middleware
$stack->push(GuzzleLoggerMiddleware::create(), 'api_logger');

// Update client configuration
$client = new \GuzzleHttp\Client([
    'handler' => $stack,
    // ... other config
]);
```

### For Laravel HTTP Client

```php
use Illuminate\Support\Facades\Http;
use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;

Http::withOptions([
    'handler' => GuzzleHandlerStackFactory::create(),
])->get('https://api.example.com');
```

## Configuration Changes

### From 1.x to 2.0

**Old Configuration (1.x):**
```php
return [
    'enabled' => true,
    'level' => 'detailed',
    'storage' => [
        // ...
    ],
    'filters' => [
        // ...
    ],
];
```

**New Configuration (2.0):**
```php
return [
    'enabled' => true, // Global toggle
    'features' => [
        'inbound' => [
            'enabled' => true,
            'level' => 'detailed',
            // ... moved from root
        ],
        'outbound' => [
            'enabled' => false, // New feature
            'level' => 'detailed',
            // ... new config
        ],
    ],
    'storage' => [
        // ... unchanged
    ],
];
```

### Backward Compatibility

The package maintains backward compatibility:
- Old configuration keys still work
- Inbound logging enabled by default
- Outbound logging disabled by default
- No code changes required for existing implementations

## Model Changes

### New Scopes Available

```php
use Ameax\ApiLogger\Models\ApiLog;

// New scopes for filtering
ApiLog::inbound()->get();      // Only inbound requests
ApiLog::outbound()->get();     // Only outbound requests
ApiLog::forService('Stripe')->get(); // By service name
ApiLog::withCorrelation($id)->get(); // By correlation ID
```

### New Accessors

```php
$log = ApiLog::first();

// New properties
echo $log->direction;           // 'inbound' or 'outbound'
echo $log->service;             // Service name (for outbound)
echo $log->correlation_identifier; // Correlation ID
echo $log->retry_attempt;       // Retry count (0 for first attempt)
echo $log->isOutbound;          // Boolean
echo $log->isInbound;           // Boolean
```

## Performance Improvements

### Query Performance

Version 2.0 uses native database columns instead of JSON queries:

**Before (1.x):**
```php
// Slow JSON queries
ApiLog::whereJsonContains('metadata->direction', 'outbound')->get();
```

**After (2.0):**
```php
// Fast indexed column queries
ApiLog::outbound()->get();
```

### Index Improvements

New indexes added for:
- `direction` column
- `service` column
- `correlation_identifier` column
- `retry_attempt` column

## Common Issues and Solutions

### Issue 1: Migration Fails

**Error:** "Column already exists"

**Solution:** You may have a partial migration. Roll back and re-run:
```bash
php artisan migrate:rollback --step=1
php artisan migrate
```

### Issue 2: Outbound Logs Not Appearing

**Check:**
1. Outbound logging is enabled:
```php
config('apilogger.features.outbound.enabled'); // Should be true
```

2. Guzzle middleware is attached:
```php
$stack = $client->getConfig('handler');
// Should contain api_logger middleware
```

3. Host isn't excluded:
```php
config('apilogger.features.outbound.filters.exclude_hosts');
```

### Issue 3: Performance Degradation

**Solution:** If you have a large existing database:

1. Add indexes manually if migration is slow:
```sql
CREATE INDEX idx_api_logs_direction ON api_logs(direction);
CREATE INDEX idx_api_logs_service ON api_logs(service);
CREATE INDEX idx_api_logs_correlation ON api_logs(correlation_identifier);
```

2. Consider archiving old logs:
```bash
php artisan api-logger:clean --days=30
```

### Issue 4: Correlation IDs Not Working

**Check:** Headers are configured correctly:
```php
'correlation' => [
    'enabled' => true,
    'headers' => ['X-Correlation-ID', 'X-Request-ID'],
],
```

## Rollback Procedure

If you need to rollback to 1.x:

1. Downgrade package:
```bash
composer require ameax/apilogger:^1.0
```

2. Rollback migration:
```bash
php artisan migrate:rollback --step=1
```

3. Clear caches:
```bash
php artisan config:clear
php artisan cache:clear
```

## Getting Help

### Documentation
- [Outbound Logging Documentation](./outbound-logging.md)
- [README](../README.md)
- [Examples](../examples/outbound/)

### Support Channels
- GitHub Issues: [github.com/ameax/apilogger/issues](https://github.com/ameax/apilogger/issues)
- Email: support@ameax.com

### Useful Commands

Check current version:
```bash
composer show ameax/apilogger
```

View configuration:
```bash
php artisan config:show apilogger
```

Test outbound logging:
```bash
php artisan tinker
>>> $client = new \GuzzleHttp\Client(['handler' => \Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory::create()]);
>>> $client->get('https://api.github.com');
>>> \Ameax\ApiLogger\Models\ApiLog::outbound()->latest()->first();
```

## Feature Comparison

| Feature | Version 1.x | Version 2.0 |
|---------|------------|-------------|
| Inbound API Logging | ✅ | ✅ |
| Outbound API Logging | ❌ | ✅ |
| Database Storage | ✅ | ✅ |
| JSON Lines Storage | ✅ | ✅ |
| Data Sanitization | ✅ | ✅ |
| Queue Support | ✅ | ✅ |
| Correlation IDs | ❌ | ✅ |
| Service Registry | ❌ | ✅ |
| Retry Tracking | ❌ | ✅ |
| Native DB Columns | ❌ | ✅ |
| Monitoring Service | ❌ | ✅ |
| Laravel 11 Support | ✅ | ✅ |
| Laravel 12 Support | ✅ | ✅ |

## Best Practices After Upgrading

1. **Start with outbound logging disabled** and enable for specific services first
2. **Use service registry** for configuring external services
3. **Implement correlation IDs** for better request tracing
4. **Monitor performance** after enabling outbound logging
5. **Configure appropriate log levels** (use 'basic' for high-volume APIs)
6. **Set up retention policies** to manage database growth
7. **Use queue processing** for better performance in production