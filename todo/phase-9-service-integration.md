# Phase 9: Service Integration & Filtering

**Status**: PENDING
**Priority**: HIGH
**Estimated Time**: 3-4 hours
**Dependencies**: Phase 8 must be completed

## Objectives
- Implement flexible filtering system for outbound logging
- Create service registry for automatic middleware registration
- Add correlation ID support for request tracing
- Enable per-service configuration

## Tasks

### 1. Create Outbound Filter Service
- [ ] Create `src/Outbound/OutboundFilterService.php`
  - Filter by host patterns (wildcards, regex)
  - Filter by service class names
  - Filter by URL patterns
  - Filter by HTTP methods
  - Filter by custom callbacks
  - Respect global enabled/disabled flag

### 2. Implement Service Registry
- [ ] Create `src/Outbound/ServiceRegistry.php`
  - Register services for automatic logging
  - Store service metadata (name, description, config)
  - Support for dynamic service registration
  - Helper methods for checking if service should be logged

### 3. Correlation ID Support
- [ ] Add correlation ID generation and propagation
  - Extract correlation ID from incoming request if present
  - Generate new correlation ID for standalone requests
  - Add correlation ID to outbound request headers (configurable)
  - Store correlation ID in metadata

### 4. Per-Service Configuration
- [ ] Extend configuration structure for per-service settings:
  ```php
  'features.outbound.services.configs' => [
      'App\Services\Haufe360ApiService' => [
          'enabled' => true,
          'log_level' => 'full',
          'sanitize_fields' => ['api_key', 'customer_id'],
          'timeout_ms' => 5000,
          'always_log_errors' => true,
      ],
  ]
  ```

### 5. Integration Helpers
- [ ] Create `src/Outbound/GuzzleHandlerStackFactory.php`
  - Factory for creating pre-configured Guzzle handler stacks
  - Automatic middleware registration based on config
  - Support for multiple middleware in stack

### 6. Service Detection
- [ ] Implement automatic service detection from Guzzle client
  - Detect service class from backtrace
  - Extract service name from client config
  - Fallback to URL-based service identification

### 7. Testing
- [ ] Unit tests for OutboundFilterService
  - Test various filter combinations
  - Test precedence rules
  - Test wildcard and regex patterns
- [ ] Unit tests for ServiceRegistry
  - Test service registration
  - Test service lookup
  - Test configuration merging
- [ ] Integration tests for correlation IDs
  - Test ID propagation
  - Test ID generation
- [ ] Test per-service configurations

### 8. Quality Checks
- [ ] Run PHPStan level 8
- [ ] Run Pint for code formatting
- [ ] Run full test suite
- [ ] Verify backwards compatibility

## Discussion Points
1. **Filter Precedence**: Which filters take priority?
   - Decision: Exclude filters override include filters, service-specific overrides global

2. **Correlation ID Header**: Which header name to use?
   - Decision: X-Correlation-ID (configurable)

3. **Service Auto-Detection**: How aggressive should auto-detection be?
   - Decision: Conservative - require explicit registration by default

4. **Configuration Inheritance**: Should service configs inherit from global?
   - Decision: Yes, with service-specific overrides

5. **Performance**: Should we cache filter results?
   - Decision: Yes, with TTL of 60 seconds for production

## Acceptance Criteria
- [ ] Services can be registered for automatic logging
- [ ] Flexible filtering system works correctly
- [ ] Correlation IDs properly link related requests
- [ ] Per-service configuration overrides work
- [ ] No performance degradation
- [ ] All tests passing

## Implementation Examples

### Service Registration Example
```php
// In service provider or config
OutboundLogger::registerService(
    Haufe360ApiService::class,
    [
        'name' => 'Haufe 360 API',
        'log_level' => 'detailed',
        'hosts' => ['*.haufe-x360.app'],
    ]
);
```

### Usage in Service
```php
class Haufe360ApiService
{
    public function __construct()
    {
        $stack = GuzzleHandlerStackFactory::create();
        // Middleware automatically added if service is registered

        $this->client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.haufe.com',
        ]);
    }
}
```

## Completion Checklist
- [ ] All tasks completed
- [ ] Unit and integration tests passing
- [ ] PHPStan level 8 passing
- [ ] Code formatted with Pint
- [ ] Documentation updated
- [ ] This file updated to COMPLETED status
- [ ] Changes committed with descriptive message