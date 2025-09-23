<?php

declare(strict_types=1);

use Ameax\ApiLogger\ApiLogger;
use Ameax\ApiLogger\ApiLoggerServiceProvider;
use Ameax\ApiLogger\Contracts\StorageInterface;
use Illuminate\Support\Facades\Config;

it('registers the service provider', function () {
    $providers = $this->app->getLoadedProviders();

    expect($providers)->toHaveKey(ApiLoggerServiceProvider::class);
});

it('registers ApiLogger as a singleton', function () {
    $logger1 = $this->app->make(ApiLogger::class);
    $logger2 = $this->app->make(ApiLogger::class);

    expect($logger1)->toBe($logger2);
});

it('publishes configuration file', function () {
    $this->artisan('vendor:publish', [
        '--provider' => ApiLoggerServiceProvider::class,
        '--tag' => 'apilogger-config',
    ])->assertExitCode(0);

    expect(config_path('apilogger.php'))->toBeFile();
});

it('merges configuration correctly', function () {
    Config::set('apilogger.custom_key', 'custom_value');

    $config = config('apilogger');

    expect($config)->toHaveKey('custom_key')
        ->and($config['custom_key'])->toBe('custom_value')
        ->and($config)->toHaveKey('enabled');
});

it('provides expected services', function () {
    $provider = new ApiLoggerServiceProvider($this->app);

    $provides = $provider->provides();

    expect($provides)->toContain(ApiLogger::class)
        ->and($provides)->toContain(StorageInterface::class);
});

it('throws exception for unknown storage driver', function () {
    Config::set('apilogger.storage.driver', 'invalid');

    $this->app->make(StorageInterface::class);
})->throws(InvalidArgumentException::class, 'Storage [invalid] is not defined.');
