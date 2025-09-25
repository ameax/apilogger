# Changelog

All notable changes to `apilogger` will be documented in this file.

## [2.0.0] - 2025-09-25

### Added
- **Outbound API Logging** - Complete support for logging external API calls via Guzzle middleware
- **Service Registry** - Configure and manage multiple external services with custom settings
- **Correlation ID Support** - Track related requests across inbound and outbound calls
- **Retry Tracking** - Monitor retry attempts with detailed metrics and success rates
- **GuzzleLoggerMiddleware** - Automatic logging for any Guzzle-based HTTP client
- **GuzzleHandlerStackFactory** - Quick setup for Guzzle clients with logging enabled
- **OutboundFilterService** - Flexible filtering system with include/exclude rules
- **CorrelationIdManager** - Automatic correlation ID generation and propagation
- **ServiceDetector** - Automatic detection of calling service from execution context
- **MonitoringService** - Built-in health checks and performance metrics
- **Enhanced Database Schema** - Native columns for better query performance:
  - `direction` column (inbound/outbound)
  - `service` column for service identification
  - `correlation_identifier` for request chaining
  - `retry_attempt` for retry tracking
- **New Model Scopes**:
  - `scopeInbound()` - Filter inbound requests
  - `scopeOutbound()` - Filter outbound requests
  - `scopeForService()` - Filter by service name
  - `scopeWithCorrelation()` - Find correlated requests
  - `scopeFailedRequests()` - Filter error responses
  - `scopeSlowRequests()` - Filter by response time
- **Model Accessors** for easy access to new metadata fields
- **Performance Metrics Collection**:
  - Service health monitoring
  - Response time percentiles
  - Success/failure rates
  - Retry pattern analysis
  - Anomaly detection

### Changed
- **Database Schema Optimization**:
  - Removed `request_id` UUID column (now using auto-increment `id`)
  - Migrated from JSON queries to native indexed columns for better performance
  - Added comprehensive indexes for all new columns
- **Configuration Structure**:
  - Split configuration into `features.inbound` and `features.outbound` sections
  - Maintained full backward compatibility with 1.x configuration
- **LogEntry Improvements**:
  - Enhanced to support both inbound and outbound request data
  - Added metadata fields for service tracking and correlation

### Fixed
- SQLite test compatibility for timestamp precision
- Query performance issues with large datasets
- JSON field indexing limitations

### Documentation
- Comprehensive outbound logging documentation
- Migration guide from 1.x to 2.0
- Integration examples for common use cases:
  - Basic Guzzle client setup
  - Service-specific configuration
  - Correlation ID usage
  - Retry handling patterns
- Updated README with new features and examples

### Developer Experience
- Zero breaking changes - full backward compatibility
- Outbound logging disabled by default for safety
- Intuitive API following Laravel conventions
- Extensive test coverage (318+ tests)

## [1.0.0] - 2024-11-01

### Added
- Initial release
- Inbound API request/response logging
- Multiple storage backends (Database, JSON Lines)
- Data sanitization for sensitive fields
- Flexible filtering options
- Queue support for async logging
- Retention policies with auto-cleanup
- Fallback storage support
- Rich model scopes for querying
- Artisan commands for maintenance
- Comprehensive test suite
