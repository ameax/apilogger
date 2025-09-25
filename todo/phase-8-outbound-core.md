# Phase 8: Outbound Logging Core

**Status**: COMPLETED ✅
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
- [x] Create `src/Outbound/` directory
- [x] Create `src/Contracts/OutboundLoggerInterface.php`
  - Define contract for outbound logging functionality
  - Methods: `logRequest()`, `logResponse()`, `shouldLog()`

### 2. Implement Guzzle Middleware
- [x] Create `src/Outbound/GuzzleLoggerMiddleware.php`
  - Implement Guzzle middleware interface (see proof of concept in `tests/ProofOfConcept/GuzzleMiddlewareTest.php`)
  - Use promise-based middleware approach for proper async handling:
    ```php
    function ($handler) {
        return function (RequestInterface $request, array $options) use ($handler) {
            // Capture request before sending
            $promise = $handler($request, $options);
            // Use promise->then() to capture response
        };
    }
    ```
  - Capture request data (method, URL, headers, body)
  - Capture response data (status, headers, body, time)
  - Handle exceptions and error responses via promise rejection callback
  - Generate unique request IDs
  - Support for correlation IDs through Guzzle options
  - Extract custom metadata from Guzzle options (service_name, correlation_id, etc.)

### 3. Create Outbound API Logger Service
- [x] Create `src/Outbound/OutboundApiLogger.php`
  - Implement OutboundLoggerInterface
  - Transform Guzzle request/response to LogEntry
  - Add metadata for outbound identification
  - Integration with DataSanitizer
  - Integration with StorageManager

### 4. Metadata Structure Definition
- [x] Define metadata JSON structure for outbound logs:
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
- [x] Update `LogEntry` DTO to handle outbound-specific fields
- [x] Ensure compatibility with existing storage drivers
- [x] Add helper methods for metadata handling

### 6. Error Handling
- [x] Handle connection timeouts
- [x] Handle DNS resolution failures
- [x] Handle SSL/TLS errors
- [x] Capture and log exception details appropriately

### 7. Testing
- [x] Unit tests for GuzzleLoggerMiddleware
  - Test request/response capture (see working examples in `tests/ProofOfConcept/GuzzleMiddlewareTest.php`)
  - Test error handling (500 responses, connection errors)
  - Test with various HTTP methods (GET, POST, PUT, DELETE)
  - Test different content types (JSON, form data, multipart)
- [x] Unit tests for OutboundApiLogger
  - Test LogEntry creation
  - Test metadata generation
  - Test sanitization integration
- [x] Integration tests with mock HTTP server
- [x] Test with real Guzzle client and public APIs (httpbin.org is useful for testing)

### 8. Quality Checks
- [x] Run PHPStan level 8
- [x] Run Pint for code formatting
- [x] Run full test suite
- [x] Check test coverage (aim for >90%)

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
- [x] Guzzle middleware successfully captures all HTTP traffic
- [x] Outbound logs are stored in same api_logs table with proper metadata
- [x] Data sanitization works for outbound requests
- [x] Error responses are properly logged
- [x] Performance overhead is minimal (< 2%)
- [x] All tests passing with good coverage

## Implementation Notes
- **Proof of Concept Available**: See `tests/ProofOfConcept/GuzzleMiddlewareTest.php` for working examples
- The proof of concept demonstrates:
  - Promise-based middleware implementation (critical for proper async handling)
  - Capturing request/response with timing information
  - Handling different content types (JSON, form data, multipart)
  - Error response handling (4xx, 5xx status codes)
  - Extracting custom metadata from Guzzle options
  - Body stream rewinding to avoid consuming the stream
- Reuse existing components where possible (DataSanitizer, StorageManager)
- Keep middleware lightweight - defer heavy processing
- Consider memory usage for large payloads
- Ensure thread safety if using in concurrent contexts
- Document any Guzzle-specific behaviors or limitations

## Completion Checklist
- [x] All tasks completed
- [x] Unit and integration tests passing
- [x] PHPStan level 8 passing
- [x] Code formatted with Pint
- [x] Test coverage > 90%
- [x] This file updated to COMPLETED status
- [x] Changes committed with descriptive message