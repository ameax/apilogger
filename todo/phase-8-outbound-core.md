# Phase 8: Outbound Logging Core

**Status**: PENDING
**Priority**: HIGH
**Estimated Time**: 4-5 hours
**Dependencies**: Phase 7 must be completed

## Objectives
- Implement Guzzle middleware for capturing external API calls
- Create OutboundApiLogger service
- Integrate with existing StorageManager and data sanitization
- Define metadata structure for outbound logs

## Tasks

### 1. Create Core Outbound Structure
- [ ] Create `src/Outbound/` directory
- [ ] Create `src/Contracts/OutboundLoggerInterface.php`
  - Define contract for outbound logging functionality
  - Methods: `logRequest()`, `logResponse()`, `shouldLog()`

### 2. Implement Guzzle Middleware
- [ ] Create `src/Outbound/GuzzleLoggerMiddleware.php`
  - Implement Guzzle middleware interface
  - Capture request data (method, URL, headers, body)
  - Capture response data (status, headers, body, time)
  - Handle exceptions and error responses
  - Generate unique request IDs
  - Support for correlation IDs

### 3. Create Outbound API Logger Service
- [ ] Create `src/Outbound/OutboundApiLogger.php`
  - Implement OutboundLoggerInterface
  - Transform Guzzle request/response to LogEntry
  - Add metadata for outbound identification
  - Integration with DataSanitizer
  - Integration with StorageManager

### 4. Metadata Structure Definition
- [ ] Define metadata JSON structure for outbound logs:
  ```json
  {
    "direction": "outbound",
    "service": "ServiceClassName",
    "service_name": "Human Readable Name",
    "host": "api.example.com",
    "correlation_id": "original-request-id",
    "retry_attempt": 0,
    "timeout": 30,
    "connection_time_ms": 150.5,
    "environment": "production"
  }
  ```

### 5. Integration Points
- [ ] Update `LogEntry` DTO to handle outbound-specific fields
- [ ] Ensure compatibility with existing storage drivers
- [ ] Add helper methods for metadata handling

### 6. Error Handling
- [ ] Handle connection timeouts
- [ ] Handle DNS resolution failures
- [ ] Handle SSL/TLS errors
- [ ] Capture and log exception details appropriately

### 7. Testing
- [ ] Unit tests for GuzzleLoggerMiddleware
  - Test request/response capture
  - Test error handling
  - Test with various HTTP methods
- [ ] Unit tests for OutboundApiLogger
  - Test LogEntry creation
  - Test metadata generation
  - Test sanitization integration
- [ ] Integration tests with mock HTTP server
- [ ] Test with real Guzzle client

### 8. Quality Checks
- [ ] Run PHPStan level 8
- [ ] Run Pint for code formatting
- [ ] Run full test suite
- [ ] Check test coverage (aim for >90%)

## Discussion Points
1. **Request ID Generation**: Use UUID v4 or timestamp-based?
   - Decision: UUID v4 for uniqueness across distributed systems

2. **Body Size Limits**: Should we truncate large request/response bodies?
   - Decision: Respect existing `performance.max_body_size` config

3. **Streaming Responses**: How to handle streaming responses?
   - Decision: Log headers and initial chunk only, add flag in metadata

4. **Retry Handling**: How to link retry attempts?
   - Decision: Use correlation_id to link, increment retry_attempt counter

5. **Performance Impact**: Acceptable overhead for logging?
   - Decision: < 2% impact on request time, use existing circuit breaker

## Acceptance Criteria
- [ ] Guzzle middleware successfully captures all HTTP traffic
- [ ] Outbound logs are stored in same api_logs table with proper metadata
- [ ] Data sanitization works for outbound requests
- [ ] Error responses are properly logged
- [ ] Performance overhead is minimal (< 2%)
- [ ] All tests passing with good coverage

## Implementation Notes
- Reuse existing components where possible (DataSanitizer, StorageManager)
- Keep middleware lightweight - defer heavy processing
- Consider memory usage for large payloads
- Ensure thread safety if using in concurrent contexts
- Document any Guzzle-specific behaviors or limitations

## Completion Checklist
- [ ] All tasks completed
- [ ] Unit and integration tests passing
- [ ] PHPStan level 8 passing
- [ ] Code formatted with Pint
- [ ] Test coverage > 90%
- [ ] This file updated to COMPLETED status
- [ ] Changes committed with descriptive message