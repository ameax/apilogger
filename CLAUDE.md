# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Important: Laravel Package Development Context

**This is a Laravel package, NOT a Laravel application.** This distinction is critical for development:

### Package Limitations & Considerations

1. **No Direct Artisan Access**:
   - Cannot run `php artisan` commands directly in the package
   - Must use Orchestra Testbench for testing Laravel features
   - Package commands are registered but not executable here

2. **Migrations**:
   - Migrations are provided but not run automatically
   - Users must publish and run migrations in their Laravel apps
   - Testing uses in-memory SQLite database via Testbench
   - Cannot modify existing migrations after release (must create new ones)
   - For package updates: create NEW migration files with timestamps

3. **Configuration**:
   - Config files must be published to host Laravel app
   - Default config values should work without publishing
   - Use `config()` helper with fallback defaults

4. **Routes & Controllers**:
   - Package doesn't have its own routes or controllers
   - Middleware is registered by the service provider
   - Users apply middleware in their applications

5. **Views & Assets**:
   - Views must be publishable, not directly accessible
   - No public directory or compiled assets
   - UI components are separate packages

6. **Database Operations**:
   - Cannot run migrations directly
   - Tests use Testbench's database setup
   - Support multiple database connections via config

7. **Testing Environment**:
   - Orchestra Testbench provides Laravel application context
   - Tests run in isolation with temporary databases
   - Feature tests simulate full Laravel environment

8. **Development Workflow**:
   - Make changes to package code
   - Run tests with `composer test`
   - Cannot "serve" or "run" the package standalone
   - Test in a real Laravel app by requiring locally

## Development Commands

### Testing
```bash
composer test             # Run all tests with Pest
composer test-coverage    # Run tests with code coverage
vendor/bin/pest          # Run Pest directly
vendor/bin/pest tests/Feature/SomeTest.php  # Run a single test file
vendor/bin/pest --filter="test name"        # Run a specific test
```

### Code Quality
```bash
composer analyse         # Run PHPStan static analysis
composer format          # Format code with Laravel Pint
vendor/bin/pint          # Run Laravel Pint directly
vendor/bin/phpstan analyse  # Run PHPStan directly
```

### Package Development
```bash
composer prepare         # Prepare the package (discovers Orchestra Testbench packages)
php artisan vendor:publish --tag="apilogger-migrations"  # Publish migrations
php artisan vendor:publish --tag="apilogger-config"      # Publish config file
php artisan vendor:publish --tag="apilogger-views"       # Publish views
```

## Architecture

This is a Laravel package built with Spatie's Laravel Package Tools. The package provides flexible API request/response logging with multiple storage backends.

### Core Components

- **Service Provider**: `ApiLoggerServiceProvider` configures the package using Spatie's PackageServiceProvider, registering config, migrations, commands, and bindings
- **Storage System**: Pluggable storage drivers (Database, JSON Lines) implementing `StorageInterface`
- **Middleware**: `LogApiRequests` middleware captures and processes API requests/responses
- **Data Sanitizer**: Configurable sanitization of sensitive data (passwords, tokens, etc.)
- **Commands**: Artisan commands for maintenance (`api-logger:clean`, `api-logger:export`)
- **Config**: Comprehensive configuration in `config/apilogger.php`
- **Models**: `ApiLog` Eloquent model for database storage
- **Testing**: Comprehensive test suite using Pest PHP

### Outbound API Logging Components (Phase 8-9)

- **GuzzleLoggerMiddleware**: Guzzle middleware for capturing external API calls
- **OutboundApiLogger**: Core service for logging outbound requests
- **OutboundFilterService**: Flexible filtering system with include/exclude rules
- **ServiceRegistry**: Central registry for managing multiple external services
- **CorrelationIdManager**: Request correlation across inbound/outbound calls
- **GuzzleHandlerStackFactory**: Factory for creating pre-configured Guzzle stacks
- **ServiceDetector**: Automatic service detection from execution context

### Package Features

- **Multiple Storage Backends**: Database (MySQL, PostgreSQL, etc.) and JSON Lines file storage
- **Flexible Configuration**: Environment-based configuration with sensible defaults
- **Data Privacy**: Automatic sanitization of sensitive fields
- **Performance Optimized**: Queue support, batch operations, configurable filtering
- **Automatic Cleanup**: Configurable retention policies with different durations for errors
- **Request Enrichment**: Automatic capture of IP, user identification, response times
- **Filtering Options**: Route-based, method-based, status code filtering
- **Export Capabilities**: Export logs in various formats (JSON, CSV, JSONL)

### Storage Drivers

1. **DatabaseStorage**:
   - Stores logs in `api_logs` table
   - Supports batch inserts for performance
   - Includes indexes for efficient querying

2. **JsonLineStorage**:
   - Stores logs in `.jsonl` files (one JSON object per line)
   - Supports daily rotation and compression
   - Efficient for high-volume logging

### Middleware Flow

1. Capture incoming request (headers, body, params)
2. Generate unique request ID
3. Process request through application
4. Capture response (status, body, time)
5. Sanitize sensitive data
6. Apply filters (routes, methods, status codes)
7. Store via configured storage driver
8. Optional: Queue for async processing

### Configuration Options

- **Logging Levels**: none, basic, detailed, full
- **Storage Settings**: Driver selection, connection details
- **Privacy Settings**: Field sanitization, masking strategies
- **Performance**: Queue usage, batch sizes, timeouts
- **Retention**: Days to keep logs, separate settings for errors
- **Filtering**: Include/exclude routes, methods, status codes

## Implementation Phases

Development is organized into phases documented in the `todo/` directory:

### Completed Phases
1. **Phase 1: Foundation** - Core architecture, interfaces, configuration ✅
2. **Phase 2: Storage** - Implement storage drivers and data sanitization ✅
3. **Phase 3: Middleware** - Request/response capture and processing ✅
4. **Phase 4: Maintenance** - Cleanup commands and retention policies ✅
5. **Phase 5: UI Integration** - Contracts for separate UI packages (future/not auto-implement)
6. **Phase 6: Testing & Docs** - Comprehensive testing and documentation (ongoing)
7. **Phase 7: Configuration & Feature Flags** - Independent inbound/outbound control ✅
8. **Phase 8: Outbound Logging Core** - Guzzle middleware for external API calls ✅
9. **Phase 9: Service Integration & Filtering** - Service registry, filtering, correlation IDs ✅

### Pending Phases
10. **Phase 10: Enhanced Metadata & Monitoring** - Extended metadata, retry tracking
11. **Phase 11: Documentation & Examples** - Complete documentation, integration examples

**Important**: Before implementing each phase, review the corresponding `todo/phase-X-*.md` file for detailed requirements and open questions.

## Key Dependencies

- **PHP 8.3+** minimum requirement (considering 8.2+ for broader compatibility)
- **Laravel 11.x or 12.x** framework support
- **spatie/laravel-package-tools** for simplified package development
- **Pest PHP** for testing (with Laravel and architecture plugins)
- **Laravel Pint** for code formatting
- **PHPStan/Larastan** for static analysis (level 8 target)

## Testing Environment

Tests use Orchestra Testbench for Laravel package testing. The test matrix includes:
- PHP versions: 8.3, 8.4
- Laravel versions: 11.x, 12.x
- Operating systems: Ubuntu, Windows
- Stability: prefer-lowest and prefer-stable dependencies

## Development Guidelines

### Before Starting Any Phase

1. **Review Previous Work**:
   - Check all completed phases for consistency
   - Review existing code structure
   - Ensure compatibility with previous implementations

2. **Resolve Open Questions**:
   - Each phase MD file contains discussion points
   - These must be resolved before coding
   - Update the MD file with decisions made

3. **Follow Laravel Best Practices**:
   - Use dependency injection
   - Follow PSR standards
   - Implement contracts/interfaces
   - Use Laravel conventions for naming

4. **Testing Requirements**:
   - Write tests before implementation (TDD)
   - Maintain >90% code coverage
   - Test edge cases and error conditions
   - Performance testing for critical paths

5. **Documentation**:
   - Update README.md with new features
   - Document all public methods
   - Add code examples for complex features
   - Keep CLAUDE.md updated with architecture changes

### Code Style

- Follow PSR-12 coding standard
- Use Laravel Pint for formatting
- PHPStan level 8 for static analysis
- Meaningful variable and method names
- Comprehensive PHPDoc blocks

### Performance Considerations

- Minimize API response time impact (<5% overhead)
- Support high-volume APIs (>1000 req/sec)
- Implement efficient database queries
- Use queues for heavy operations
- Implement circuit breakers for storage failures

### Security & Privacy

- Never log passwords in plain text
- Sanitize sensitive fields by default
- Support different masking strategies
- Ensure PII compliance options
- Secure file permissions for JSON logs
- migrations are store as .stub file an registered in ServiceProvider. For tests we can add a symlink to have the same migration also as .php file