<?php

declare(strict_types=1);

namespace Ameax\ApiLogger;

use Ameax\ApiLogger\Commands\ApiLoggerCommand;
use Ameax\ApiLogger\Contracts\StorageInterface;
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
        // Register the main ApiLogger singleton
        $this->app->singleton(ApiLogger::class, function ($app) {
            return new ApiLogger(
                config: $app['config']->get('apilogger', []),
            );
        });

        // Register storage interface binding (will be implemented in Phase 2)
        // For now, we'll prepare the binding structure
        $this->app->bind(StorageInterface::class, function ($app) {
            $driver = config('apilogger.storage.driver', 'database');

            // Storage implementations will be added in Phase 2
            // This is a placeholder that will be replaced
            return match ($driver) {
                'database' => null, // Will be: new DatabaseStorage(...)
                'jsonline' => null, // Will be: new JsonLineStorage(...)
                default => throw new \InvalidArgumentException("Unknown storage driver: {$driver}"),
            };
        });
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
        ];
    }
}
