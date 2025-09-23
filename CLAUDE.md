# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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

This is a Laravel package built with Spatie's Laravel Package Tools. The package follows standard Laravel package conventions:

- **Service Provider**: `ApiLoggerServiceProvider` configures the package using Spatie's PackageServiceProvider, registering config, views, migrations, and commands
- **Main Class**: `ApiLogger` is the primary class (currently empty, ready for implementation)
- **Facade**: `ApiLogger` facade provides static access to the ApiLogger class
- **Command**: `ApiLoggerCommand` available for console operations
- **Config**: Package configuration stored in `config/apilogger.php`
- **Migrations**: Database migrations in `database/migrations/` (stub files)
- **Testing**: Uses Pest PHP testing framework with Laravel and architecture plugins

## Key Dependencies

- **PHP 8.4+** minimum requirement
- **Laravel 11.x or 12.x** framework support
- **spatie/laravel-package-tools** for simplified package development
- **Pest PHP** for testing (with Laravel and architecture plugins)
- **Laravel Pint** for code formatting
- **PHPStan/Larastan** for static analysis (level 5)

## Testing Environment

Tests use Orchestra Testbench for Laravel package testing. The test matrix includes:
- PHP versions: 8.3, 8.4
- Laravel versions: 11.x, 12.x
- Operating systems: Ubuntu, Windows
- Stability: prefer-lowest and prefer-stable dependencies