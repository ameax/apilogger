<?php

declare(strict_types=1);

namespace Ameax\ApiLogger;

use Ameax\ApiLogger\Commands\ApiLoggerCommand;
use Ameax\ApiLogger\Contracts\StorageInterface;
use Ameax\ApiLogger\Services\DataSanitizer;
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
        // Additional boot logic can be added here
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

        // Register the main ApiLogger singleton
        $this->app->singleton(ApiLogger::class, function ($app) {
            return new ApiLogger(
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register storage interface binding to use StorageManager
        $this->app->bind(StorageInterface::class, function ($app) {
            return $app->make(StorageManager::class)->store();
        });

        // Register 'apilogger.storage' alias for StorageManager
        $this->app->alias(StorageManager::class, 'apilogger.storage');

        // Register 'apilogger.sanitizer' alias for DataSanitizer
        $this->app->alias(DataSanitizer::class, 'apilogger.sanitizer');
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
            'apilogger.storage',
            'apilogger.sanitizer',
        ];
    }
}
