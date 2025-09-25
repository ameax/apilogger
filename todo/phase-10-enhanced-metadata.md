# Phase 10: Enhanced Metadata & Monitoring

**Status**: PENDING
**Priority**: MEDIUM
**Estimated Time**: 3-4 hours
**Dependencies**: Phase 9 must be completed

## Objectives
- Enhance database schema for better metadata support
- Add model scopes for filtering inbound/outbound logs
- Implement retry tracking and performance metrics
- Prepare for dashboard and monitoring features

## Tasks

### 1. Update Database Migration
- [ ] Modify existing migration `create_api_logs_table.php`
  - Add index on `metadata->>'$.direction'` for performance
  - Add index on `metadata->>'$.service'` for filtering
  - Add index on `metadata->>'$.correlation_id'` for tracing
  - Consider adding generated columns for frequently queried metadata

### 2. Enhance ApiLog Model
- [ ] Add scopes to `src/Models/ApiLog.php`
  - `scopeInbound($query)` - filter inbound requests
  - `scopeOutbound($query)` - filter outbound requests
  - `scopeForService($query, $service)` - filter by service
  - `scopeWithCorrelation($query, $correlationId)` - find related requests
  - `scopeFailedRequests($query)` - filter 4xx/5xx responses
  - `scopeSlowRequests($query, $thresholdMs)` - filter slow requests

### 3. Add Model Accessors
- [ ] Create accessors for common metadata fields
  - `getDirectionAttribute()` - return 'inbound' or 'outbound'
  - `getServiceAttribute()` - return service name
  - `getCorrelationIdAttribute()` - return correlation ID
  - `getRetryAttemptAttribute()` - return retry count
  - `isOutboundAttribute()` - boolean check
  - `isInboundAttribute()` - boolean check

### 4. Performance Metrics Collection
- [ ] Add performance metric calculations
  - Average response time per service
  - Success/failure rates per service
  - Retry success rates
  - Connection time vs total time analysis
  - Peak usage time detection

### 5. Retry Tracking Enhancement
- [ ] Implement retry tracking features
  - Link retry attempts via correlation ID
  - Track retry patterns
  - Calculate retry success rates
  - Identify frequently failing endpoints

### 6. Monitoring Helpers
- [ ] Create `src/Services\MonitoringService.php`
  - Health check methods for external services
  - Alert threshold detection
  - Anomaly detection helpers
  - Service availability calculations

### 7. Query Optimization
- [ ] Optimize common query patterns
  - Add query hints for JSON fields
  - Create composite indexes where needed
  - Add database views for complex queries (optional)

### 8. Testing
- [ ] Test database migrations
  - Test index creation
  - Test rollback functionality
- [ ] Test model scopes and accessors
  - Test with various metadata structures
  - Test performance with large datasets
- [ ] Test monitoring service
  - Test metric calculations
  - Test threshold detection
- [ ] Performance testing
  - Benchmark query performance
  - Test with 100k+ records

### 9. Quality Checks
- [ ] Run PHPStan level 8
- [ ] Run Pint for code formatting
- [ ] Run full test suite
- [ ] Check query performance with EXPLAIN

## Discussion Points
1. **Generated Columns**: Should we use MySQL generated columns for metadata fields?
   - Decision: Start with indexes, add generated columns if performance requires

2. **View Creation**: Should we create database views for common queries?
   - Decision: Not initially, can add if needed for reporting

3. **Metrics Storage**: Should metrics be cached or calculated on-demand?
   - Decision: Calculate on-demand initially, add caching if needed

4. **Alert Thresholds**: How should thresholds be configured?
   - Decision: Configurable per service with global defaults

## Acceptance Criteria
- [ ] Database indexes improve query performance significantly
- [ ] Model scopes work correctly and efficiently
- [ ] Retry tracking properly links related requests
- [ ] Performance metrics are accurate
- [ ] No breaking changes to existing functionality
- [ ] All tests passing

## Example Usage

### Using Model Scopes
```php
// Get all outbound requests to Haufe API
$logs = ApiLog::outbound()
    ->forService('Haufe360ApiService')
    ->where('response_code', '>=', 400)
    ->get();

// Get all requests in a correlation chain
$chain = ApiLog::withCorrelation($correlationId)
    ->orderBy('created_at')
    ->get();

// Get slow outbound requests
$slowRequests = ApiLog::outbound()
    ->slowRequests(5000) // > 5 seconds
    ->today()
    ->get();
```

### Monitoring Example
```php
$monitor = app(MonitoringService::class);

// Check service health
$health = $monitor->checkServiceHealth('Haufe360ApiService');

// Get performance metrics
$metrics = $monitor->getServiceMetrics('Haufe360ApiService',
    now()->subHours(24),
    now()
);
```

## Performance Targets
- Query for last 100 logs: < 50ms
- Filter by service (10k records): < 100ms
- Correlation chain lookup: < 100ms
- Metrics calculation (1 day): < 500ms

## Completion Checklist
- [ ] All tasks completed
- [ ] Database migrations tested
- [ ] Model enhancements working
- [ ] Performance targets met
- [ ] PHPStan level 8 passing
- [ ] Code formatted with Pint
- [ ] This file updated to COMPLETED status
- [ ] Changes committed with descriptive message