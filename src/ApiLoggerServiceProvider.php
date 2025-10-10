<?php

declare(strict_types=1);

namespace Ameax\ApiLogger;

use Ameax\ApiLogger\Commands\ApiLoggerCommand;
use Ameax\ApiLogger\Contracts\OutboundLoggerInterface;
use Ameax\ApiLogger\Contracts\StorageInterface;
use Ameax\ApiLogger\Middleware\LogApiRequests;
use Ameax\ApiLogger\Outbound\OutboundApiLogger;
use Ameax\ApiLogger\Outbound\OutboundFilterService;
use Ameax\ApiLogger\Outbound\ServiceDetector;
use Ameax\ApiLogger\Services\DataSanitizer;
use Ameax\ApiLogger\Services\FilterService;
use Ameax\ApiLogger\Services\RequestCapture;
use Ameax\ApiLogger\Services\ResponseCapture;
use Ameax\ApiLogger\Support\CorrelationIdManager;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ApiLoggerServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('apilogger')
            ->hasConfigFile()
            ->hasMigration('create_api_logs_table')
            ->hasViews()
            ->hasCommand(ApiLoggerCommand::class);
    }

    /**
     * Register any package services.
     */
    public function packageRegistered(): void
    {
        // Merge default config
        $this->mergeConfigFrom(
            __DIR__.'/../config/apilogger.php',
            'apilogger'
        );

        // Register bindings
        $this->registerBindings();
    }

    /**
     * Bootstrap any package services.
     */
    public function packageBooted(): void
    {
        // Register features based on configuration
        $this->registerFeatures();
    }

    /**
     * Register service bindings.
     */
    protected function registerBindings(): void
    {
        // Register the StorageManager singleton
        $this->app->scoped(StorageManager::class, function ($app) {
            return new StorageManager($app);
        });

        // Register the DataSanitizer singleton
        $this->app->singleton(DataSanitizer::class, function ($app) {
            return new DataSanitizer(
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register the FilterService singleton
        $this->app->singleton(FilterService::class, function ($app) {
            return new FilterService(
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register the RequestCapture singleton
        $this->app->singleton(RequestCapture::class, function ($app) {
            return new RequestCapture(
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register the ResponseCapture singleton
        $this->app->singleton(ResponseCapture::class, function ($app) {
            return new ResponseCapture(
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register the main ApiLogger singleton
        $this->app->singleton(ApiLogger::class, function ($app) {
            return new ApiLogger(
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register the LogApiRequests middleware
        $this->app->scoped(LogApiRequests::class, function ($app) {
            return new LogApiRequests(
                storageManager: $app->make(StorageManager::class),
                sanitizer: $app->make(DataSanitizer::class),
                filterService: $app->make(FilterService::class),
                requestCapture: $app->make(RequestCapture::class),
                responseCapture: $app->make(ResponseCapture::class),
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register storage interface binding to use StorageManager
        $this->app->bind(StorageInterface::class, function ($app) {
            return $app->make(StorageManager::class)->store();
        });

        // Register Correlation ID Manager
        $this->app->singleton(CorrelationIdManager::class, function ($app) {
            return new CorrelationIdManager(
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register Outbound Filter Service
        $this->app->singleton(OutboundFilterService::class, function ($app) {
            return new OutboundFilterService(
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register Service Detector
        $this->app->singleton(ServiceDetector::class, function ($app) {
            return new ServiceDetector;
        });

        // Register Outbound API Logger
        $this->app->singleton(OutboundApiLogger::class, function ($app) {
            return new OutboundApiLogger(
                storageManager: $app->make(StorageManager::class),
                dataSanitizer: $app->make(DataSanitizer::class),
                filterService: $app->make(OutboundFilterService::class),
                correlationIdManager: $app->make(CorrelationIdManager::class),
                config: $app['config']->get('apilogger', []),
            );
        });

        // Bind interface to implementation
        $this->app->bind(OutboundLoggerInterface::class, OutboundApiLogger::class);

        // Register aliases
        $this->app->alias(StorageManager::class, 'apilogger.storage');
        $this->app->alias(DataSanitizer::class, 'apilogger.sanitizer');
        $this->app->alias(FilterService::class, 'apilogger.filter');
        $this->app->alias(RequestCapture::class, 'apilogger.request');
        $this->app->alias(ResponseCapture::class, 'apilogger.response');
        $this->app->alias(LogApiRequests::class, 'apilogger.middleware');
        $this->app->alias(CorrelationIdManager::class, 'apilogger.correlation');
        $this->app->alias(OutboundFilterService::class, 'apilogger.outbound.filter');
        $this->app->alias(OutboundApiLogger::class, 'apilogger.outbound.logger');
    }

    /**
     * Register features based on configuration.
     */
    protected function registerFeatures(): void
    {
        // Check if package is enabled at all
        if (! config('apilogger.enabled', true)) {
            return;
        }

        // Register inbound API logging
        if (config('apilogger.features.inbound.enabled', true)) {
            $this->registerInboundLogging();
        }

        // Register outbound API logging
        if (config('apilogger.features.outbound.enabled', false)) {
            $this->registerOutboundLogging();
        }
    }

    /**
     * Register inbound API logging (incoming requests).
     */
    protected function registerInboundLogging(): void
    {
        // Get the router
        $router = $this->app->make(Router::class);

        // Register middleware alias
        $router->aliasMiddleware('api.logger', LogApiRequests::class);

        // Optionally add to global middleware if configured
        if (config('apilogger.middleware.global', false)) {
            $kernel = $this->app->make(Kernel::class);
            $kernel->pushMiddleware(LogApiRequests::class);
        }

        // Optionally add to API middleware group if configured
        if (config('apilogger.middleware.api_group', true)) {
            $router->pushMiddlewareToGroup('api', LogApiRequests::class);
        }
    }

    /**
     * Register outbound API logging (external API calls).
     */
    protected function registerOutboundLogging(): void
    {
        // Check if Guzzle is available
        if (! class_exists(\GuzzleHttp\Client::class)) {
            Log::warning(
                'ApiLogger: Outbound logging is enabled but Guzzle is not installed. '.
                'Please install guzzlehttp/guzzle to use outbound API logging.'
            );

            return;
        }

        // Auto-register middleware for all Guzzle clients if configured
        if (config('apilogger.features.outbound.auto_register', false)) {
            // This would require hooking into Guzzle client creation
            // which is complex and framework-specific
            // Users should manually add middleware to their Guzzle clients
            Log::info(
                'ApiLogger: Auto-registration of outbound middleware is not yet implemented. '.
                'Please manually add the middleware to your Guzzle clients using GuzzleHandlerStackFactory::create()'
            );
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            ApiLogger::class,
            StorageInterface::class,
            StorageManager::class,
            DataSanitizer::class,
            FilterService::class,
            RequestCapture::class,
            ResponseCapture::class,
            LogApiRequests::class,
            'apilogger.storage',
            'apilogger.sanitizer',
            'apilogger.filter',
            'apilogger.request',
            'apilogger.response',
            'apilogger.middleware',
        ];
    }
}
