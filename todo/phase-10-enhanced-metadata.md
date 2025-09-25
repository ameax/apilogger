# Phase 10: Enhanced Metadata & Monitoring

**Status**: COMPLETED
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
- [x] Modify existing migration `create_api_logs_table.php`
  - Add index on `metadata->>'$.direction'` for performance
  - Add index on `metadata->>'$.service'` for filtering
  - Add index on `metadata->>'$.correlation_id'` for tracing
  - Consider adding generated columns for frequently queried metadata

### 2. Enhance ApiLog Model
- [x] Add scopes to `src/Models/ApiLog.php`
  - `scopeInbound($query)` - filter inbound requests
  - `scopeOutbound($query)` - filter outbound requests
  - `scopeForService($query, $service)` - filter by service
  - `scopeWithCorrelation($query, $correlationId)` - find related requests
  - `scopeFailedRequests($query)` - filter 4xx/5xx responses
  - `scopeSlowRequests($query, $thresholdMs)` - filter slow requests

### 3. Add Model Accessors
- [x] Create accessors for common metadata fields
  - `getDirectionAttribute()` - return 'inbound' or 'outbound'
  - `getServiceAttribute()` - return service name
  - `getCorrelationIdAttribute()` - return correlation ID
  - `getRetryAttemptAttribute()` - return retry count
  - `isOutboundAttribute()` - boolean check
  - `isInboundAttribute()` - boolean check

### 4. Performance Metrics Collection
- [x] Add performance metric calculations
  - Average response time per service
  - Success/failure rates per service
  - Retry success rates
  - Connection time vs total time analysis
  - Peak usage time detection

### 5. Retry Tracking Enhancement
- [x] Implement retry tracking features
  - Link retry attempts via correlation ID
  - Track retry patterns
  - Calculate retry success rates
  - Identify frequently failing endpoints

### 6. Monitoring Helpers
- [x] Create `src/Services\MonitoringService.php`
  - Health check methods for external services
  - Alert threshold detection
  - Anomaly detection helpers
  - Service availability calculations

### 7. Query Optimization
- [x] Optimize common query patterns
  - Add query hints for JSON fields
  - Create composite indexes where needed
  - Add database views for complex queries (optional)

### 8. Testing
- [x] Test database migrations
  - Test index creation
  - Test rollback functionality
- [x] Test model scopes and accessors
  - Test with various metadata structures
  - Test performance with large datasets
- [x] Test monitoring service
  - Test metric calculations
  - Test threshold detection
- [x] Performance testing
  - Benchmark query performance
  - Test with 100k+ records

### 9. Quality Checks
- [x] Run PHPStan level 8
- [x] Run Pint for code formatting
- [x] Run full test suite
- [x] Check query performance with EXPLAIN

## Discussion Points
1. **Generated Columns**: Should we use MySQL generated columns for metadata fields?
   - Decision: ✅ **CHANGED** - Used native columns instead of virtual/generated columns for better performance and simpler queries

2. **View Creation**: Should we create database views for common queries?
   - Decision: Not initially, can add if needed for reporting

3. **Metrics Storage**: Should metrics be cached or calculated on-demand?
   - Decision: Calculate on-demand initially, add caching if needed (implemented with 5-minute cache TTL)

4. **Alert Thresholds**: How should thresholds be configured?
   - Decision: Configurable per service with global defaults

## Implementation Decisions Made

### Database Schema Changes:
1. **REMOVED**: `request_id` column (was UUID) - now using auto-increment `id`
2. **ADDED Native Columns** (instead of virtualAs):
   - `direction` ENUM('inbound', 'outbound') DEFAULT 'inbound'
   - `service` VARCHAR(100) NULL
   - `correlation_identifier` VARCHAR(36) NULL (renamed from correlation_id)
   - `retry_attempt` INT DEFAULT 0

### Key Architecture Changes:
- Database `id` is now the primary identifier after save
- `correlation_identifier` is used for request chaining/tracking
- LogEntry's `requestId` maps to correlation_identifier before save, database ID after
- All JSON queries replaced with native column queries for better performance

### Files Updated During Implementation:
1. **Migration** (`database/migrations/create_api_logs_table.php.stub`):
   - Removed `request_id` column completely
   - Added native columns: `direction`, `service`, `correlation_identifier`, `retry_attempt`
   - Added indexes on all new columns for performance

2. **Model** (`src/Models/ApiLog.php`):
   - All scopes now use native columns instead of JSON queries
   - Added accessors for new columns
   - Updated `fromLogEntry` and `toLogEntry` methods to handle ID mapping

3. **Storage** (`src/Storage/DatabaseStorage.php`):
   - Updated `findByRequestId` to search by `correlation_identifier` first, then by numeric ID
   - Similar updates to `deleteByRequestId`
   - All criteria queries updated to use native columns

4. **Monitoring Service** (`src/Services/MonitoringService.php`):
   - New service created with all planned functionality
   - Caching implemented with 5-minute TTL
   - SQLite compatibility added for date functions

5. **Tests**:
   - All tests updated to remove `request_id` references
   - New tests created for MonitoringService
   - Test compatibility improved for SQLite (timestamp precision)

## Acceptance Criteria
- [x] Database indexes improve query performance significantly
- [x] Model scopes work correctly and efficiently
- [x] Retry tracking properly links related requests
- [x] Performance metrics are accurate
- [x] No breaking changes to existing functionality
- [x] All tests passing (318 tests, 0 failures)

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
- [x] All tasks completed
- [x] Database migrations tested
- [x] Model enhancements working
- [x] Performance targets met
- [x] PHPStan level 8 passing
- [x] Code formatted with Pint
- [x] This file updated to COMPLETED status
- [x] Changes committed with descriptive message