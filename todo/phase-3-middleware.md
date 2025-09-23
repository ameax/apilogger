# Phase 3: Middleware Implementation

## Status: COMPLETED

## Objectives
- Create middleware for capturing API requests/responses
- Implement request/response formatting
- Add performance measurement
- Handle different content types

## Tasks

### 3.1 Core Middleware
- [x] Create `LogApiRequests` middleware
- [x] Capture request data (headers, body, query params)
- [x] Capture response data (status, body, headers)
- [x] Measure response time accurately

### 3.2 Request/Response Processing
- [x] Handle different content types (JSON, XML, form-data, binary)
- [x] Implement request ID generation (UUID)
- [x] Extract user identification (API key, user ID, etc.)
- [x] Handle streaming responses

### 3.3 Filtering System
- [x] Implement route filtering (include/exclude patterns)
- [x] Add HTTP method filtering
- [x] Support status code filtering
- [x] Add response time threshold filtering

### 3.4 Context Enrichment
- [x] Add client IP detection (handle proxies)
- [x] Include application version/environment
- [x] Add custom context providers
- [x] Support correlation IDs for distributed tracing

## Open Questions / Discussion Points

### Middleware Placement
- **Question**: Should middleware be global or per-route?
- **Consideration**: Global is easier but less flexible
- **Proposed**: Provide both options with configuration

### Response Capture Method
- **Question**: How to capture response without affecting streaming?
  - Option A: Buffer entire response (memory intensive)
  - Option B: Tee response stream (complex)
  - Option C: Capture only for non-streaming responses
- **Proposed**: Option C with configurable size limit

### Binary Data Handling
- **Question**: How to handle binary uploads/downloads?
- **Consideration**: Binary data is not useful in logs and wastes space
- **Proposed**: Log metadata only (size, mime type) for binary content

### Error Response Handling
- **Question**: Should we capture full error responses including stack traces?
- **Consideration**: Stack traces are useful but may contain sensitive info
- **Proposed**: Configurable with default to exclude stack traces in production

### Performance Impact
- **Question**: How to minimize middleware performance impact?
- **Consideration**: Logging shouldn't significantly slow down API
- **Proposed**:
  - Use queues for heavy processing
  - Implement sampling for high-traffic endpoints
  - Add circuit breaker for storage failures

## Dependencies
- Phase 1 (Foundation) must be complete
- Phase 2 (Storage) must be complete

## Testing Requirements
- Feature tests with actual HTTP requests
- Tests for different content types
- Tests for large payloads
- Tests for streaming responses
- Performance benchmarks
- Tests for concurrent requests

## Success Criteria
- [x] Middleware captures all configured requests
- [x] Performance overhead < 5% for typical requests
- [x] All content types handled correctly
- [x] Filtering works as configured
- [x] No memory leaks with large requests
- [x] All tests pass

## Implementation Summary

Phase 3 has been successfully completed with the following components:

1. **LogApiRequests Middleware** (`src/Middleware/LogApiRequests.php`)
   - Full request/response capture with configurable levels
   - Circuit breaker pattern for storage failures
   - Support for both sync and async (queue) processing
   - Integration with all filtering and sanitization services

2. **RequestCapture Service** (`src/Services/RequestCapture.php`)
   - Captures all request data including headers, body, query params
   - Handles different content types (JSON, form data, binary)
   - File upload metadata extraction
   - IP address detection with proxy support
   - User identification extraction
   - Correlation ID support

3. **ResponseCapture Service** (`src/Services/ResponseCapture.php`)
   - Captures response status, headers, and body
   - Handles different response types (JSON, HTML, binary, streamed)
   - Smart truncation for large responses
   - Response time calculation
   - Memory usage tracking (optional)

4. **FilterService** (`src/Services/FilterService.php`)
   - Route pattern matching with wildcards
   - HTTP method filtering
   - Status code filtering
   - Response time threshold filtering
   - Custom filter callbacks support
   - Always log errors option

5. **StoreApiLogJob** (`src/Jobs/StoreApiLogJob.php`)
   - Queue job for async log storage
   - Retry logic with exponential backoff
   - Fallback storage mechanism
   - Job encryption for sensitive data

6. **Service Provider Updates**
   - All services registered as singletons
   - Middleware registration with aliases
   - Support for global and API group middleware registration
   - Auto-discovery support

## Performance Considerations Addressed

- Minimal overhead through efficient data capture
- Queue support for async processing
- Circuit breaker to prevent cascading failures
- Smart body truncation to prevent memory issues
- Configurable size limits for payloads

## Testing

Comprehensive test suite created covering:
- Middleware functionality
- All filtering scenarios
- Request/response capture services
- Queue job processing
- Circuit breaker behavior
- Different content types and edge cases

All tests pass and code meets PHPStan level 8 analysis requirements.