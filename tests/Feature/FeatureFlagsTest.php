<?php

declare(strict_types=1);

use Ameax\ApiLogger\ApiLoggerServiceProvider;
use Ameax\ApiLogger\Middleware\LogApiRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Reset config before each test
    Config::set('apilogger', config('apilogger'));
});

describe('Feature Flags Configuration', function () {
    it('enables inbound logging by default', function () {
        expect(config('apilogger.features.inbound.enabled'))->toBeTrue();
    });

    it('disables outbound logging by default', function () {
        expect(config('apilogger.features.outbound.enabled'))->toBeFalse();
    });

    it('disables outbound auto-registration by default', function () {
        expect(config('apilogger.features.outbound.auto_register'))->toBeFalse();
    });

    it('has empty service include filters by default', function () {
        expect(config('apilogger.features.outbound.filters.include_services'))->toBeArray()->toBeEmpty();
    });

    it('has empty service exclude filters by default', function () {
        expect(config('apilogger.features.outbound.filters.exclude_services'))->toBeArray()->toBeEmpty();
    });

    it('has empty host include filters by default', function () {
        expect(config('apilogger.features.outbound.filters.include_hosts'))->toBeArray()->toBeEmpty();
    });

    it('has default host exclude filters', function () {
        $excludeHosts = config('apilogger.features.outbound.filters.exclude_hosts');
        expect($excludeHosts)->toBeArray();
        expect($excludeHosts)->toContain('localhost');
        expect($excludeHosts)->toContain('127.0.0.1');
        expect($excludeHosts)->toContain('*.local');
    });
});

describe('Service Provider Feature Registration', function () {
    it('does not register any features when package is disabled', function () {
        // Test the registration methods directly
        $serviceProvider = new ApiLoggerServiceProvider($this->app);

        // Disable the package
        Config::set('apilogger.enabled', false);

        // Use reflection to call the protected method directly
        $reflection = new ReflectionClass($serviceProvider);
        $method = $reflection->getMethod('registerFeatures');
        $method->setAccessible(true);

        // Clear any existing middleware first
        $router = app(Router::class);
        $routerReflection = new ReflectionClass($router);
        $property = $routerReflection->getProperty('middlewareGroups');
        $property->setAccessible(true);
        $currentGroups = $property->getValue($router);
        if (isset($currentGroups['api'])) {
            $currentGroups['api'] = array_filter($currentGroups['api'], fn ($m) => $m !== LogApiRequests::class);
            $property->setValue($router, $currentGroups);
        }

        // Call registerFeatures which should do nothing when disabled
        $method->invoke($serviceProvider);

        // Check that middleware is not registered
        $middlewareGroups = $router->getMiddlewareGroups();
        expect($middlewareGroups['api'] ?? [])->not->toContain(LogApiRequests::class);
    });

    it('registers inbound middleware when inbound feature is enabled', function () {
        // Enable inbound, disable outbound
        Config::set('apilogger.enabled', true);
        Config::set('apilogger.features.inbound.enabled', true);
        Config::set('apilogger.features.outbound.enabled', false);
        Config::set('apilogger.middleware.api_group', true);

        // Re-register the service provider
        $this->app->register(ApiLoggerServiceProvider::class);

        // Check that middleware is registered
        $router = app(Router::class);
        $middlewareGroups = $router->getMiddlewareGroups();

        expect($middlewareGroups['api'] ?? [])->toContain(LogApiRequests::class);
    });

    it('does not register inbound middleware when inbound feature is disabled', function () {
        // Disable inbound
        Config::set('apilogger.enabled', true);
        Config::set('apilogger.features.inbound.enabled', false);
        Config::set('apilogger.middleware.api_group', true);

        // Clear existing middleware groups
        $router = app(Router::class);
        $reflection = new ReflectionClass($router);
        $property = $reflection->getProperty('middlewareGroups');
        $property->setAccessible(true);
        $property->setValue($router, ['api' => []]);

        // Re-register the service provider
        $this->app->register(ApiLoggerServiceProvider::class);

        // Check that middleware is not registered
        $middlewareGroups = $router->getMiddlewareGroups();

        expect($middlewareGroups['api'] ?? [])->not->toContain(LogApiRequests::class);
    });

    it('registers middleware alias when inbound is enabled', function () {
        // Enable inbound
        Config::set('apilogger.enabled', true);
        Config::set('apilogger.features.inbound.enabled', true);

        // Re-register the service provider
        $this->app->register(ApiLoggerServiceProvider::class);

        // Check that middleware alias is registered
        $router = app(Router::class);
        $middleware = $router->getMiddleware();

        expect($middleware)->toHaveKey('api.logger');
        expect($middleware['api.logger'])->toBe(LogApiRequests::class);
    });

    it('logs warning when outbound is enabled but Guzzle is not installed', function () {
        // Skip this test if Guzzle is actually installed (which it likely is in dev)
        if (class_exists(\GuzzleHttp\Client::class)) {
            $this->markTestSkipped('Guzzle is installed, cannot test warning message');

            return;
        }

        // Enable outbound
        Config::set('apilogger.enabled', true);
        Config::set('apilogger.features.outbound.enabled', true);

        // Mock Log facade to capture warning
        Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::pattern('/Guzzle is not installed/'));

        // Register the service provider which should trigger the warning
        $this->app->register(ApiLoggerServiceProvider::class);
    });
});

describe('Feature Flag Combinations', function () {
    it('can enable both inbound and outbound logging', function () {
        Config::set('apilogger.enabled', true);
        Config::set('apilogger.features.inbound.enabled', true);
        Config::set('apilogger.features.outbound.enabled', true);

        expect(config('apilogger.features.inbound.enabled'))->toBeTrue();
        expect(config('apilogger.features.outbound.enabled'))->toBeTrue();
    });

    it('can disable both inbound and outbound logging', function () {
        Config::set('apilogger.enabled', true);
        Config::set('apilogger.features.inbound.enabled', false);
        Config::set('apilogger.features.outbound.enabled', false);

        expect(config('apilogger.features.inbound.enabled'))->toBeFalse();
        expect(config('apilogger.features.outbound.enabled'))->toBeFalse();
    });

    it('can enable only inbound logging', function () {
        Config::set('apilogger.enabled', true);
        Config::set('apilogger.features.inbound.enabled', true);
        Config::set('apilogger.features.outbound.enabled', false);

        expect(config('apilogger.features.inbound.enabled'))->toBeTrue();
        expect(config('apilogger.features.outbound.enabled'))->toBeFalse();
    });

    it('can enable only outbound logging', function () {
        Config::set('apilogger.enabled', true);
        Config::set('apilogger.features.inbound.enabled', false);
        Config::set('apilogger.features.outbound.enabled', true);

        expect(config('apilogger.features.inbound.enabled'))->toBeFalse();
        expect(config('apilogger.features.outbound.enabled'))->toBeTrue();
    });
});

describe('Environment Variable Configuration', function () {
    it('can configure inbound feature via environment variable', function () {
        // Simulate environment variable
        putenv('API_LOGGER_INBOUND_ENABLED=false');

        // Clear config cache and reload
        Config::set('apilogger.features.inbound.enabled', env('API_LOGGER_INBOUND_ENABLED', true));

        expect(config('apilogger.features.inbound.enabled'))->toBeFalse();

        // Clean up
        putenv('API_LOGGER_INBOUND_ENABLED');
    });

    it('can configure outbound feature via environment variable', function () {
        // Simulate environment variable
        putenv('API_LOGGER_OUTBOUND_ENABLED=true');

        // Clear config cache and reload
        Config::set('apilogger.features.outbound.enabled', env('API_LOGGER_OUTBOUND_ENABLED', false));

        expect(config('apilogger.features.outbound.enabled'))->toBeTrue();

        // Clean up
        putenv('API_LOGGER_OUTBOUND_ENABLED');
    });

    it('can configure auto-registration via environment variable', function () {
        // Simulate environment variable
        putenv('API_LOGGER_OUTBOUND_AUTO_REGISTER=true');

        // Clear config cache and reload
        Config::set('apilogger.features.outbound.auto_register', env('API_LOGGER_OUTBOUND_AUTO_REGISTER', false));

        expect(config('apilogger.features.outbound.auto_register'))->toBeTrue();

        // Clean up
        putenv('API_LOGGER_OUTBOUND_AUTO_REGISTER');
    });
});
