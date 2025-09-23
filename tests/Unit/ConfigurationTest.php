<?php

declare(strict_types=1);

use Ameax\ApiLogger\ApiLogger;

it('loads configuration correctly', function () {
    $config = config('apilogger');

    expect($config)->toBeArray()
        ->and($config)->toHaveKeys([
            'enabled',
            'level',
            'storage',
            'retention',
            'privacy',
            'performance',
            'filters',
            'enrichment',
            'export',
        ]);
});

it('has correct default configuration values', function () {
    $config = config('apilogger');

    expect($config['enabled'])->toBe(true)
        ->and($config['level'])->toBe('detailed')
        ->and($config['storage']['driver'])->toBe('database')
        ->and($config['retention']['days'])->toBe(30)
        ->and($config['retention']['error_days'])->toBe(90)
        ->and($config['performance']['use_queue'])->toBe(false);
});

it('validates storage driver configuration', function () {
    $config = config('apilogger');

    expect($config['storage'])->toHaveKeys(['driver', 'database', 'jsonline'])
        ->and($config['storage']['database'])->toHaveKeys(['connection', 'table'])
        ->and($config['storage']['jsonline'])->toHaveKeys(['path', 'filename_format', 'rotate_daily']);
});

it('has privacy configuration with sensitive fields', function () {
    $config = config('apilogger.privacy');

    expect($config['exclude_fields'])->toBeArray()
        ->and($config['exclude_fields'])->toContain('password')
        ->and($config['exclude_fields'])->toContain('token')
        ->and($config['exclude_fields'])->toContain('api_key')
        ->and($config['mask_fields'])->toContain('email')
        ->and($config['masking_strategy'])->toBe('partial');
});

it('has filtering configuration', function () {
    $config = config('apilogger.filters');

    expect($config)->toHaveKeys([
        'include_routes',
        'exclude_routes',
        'include_methods',
        'exclude_methods',
        'include_status_codes',
        'exclude_status_codes',
        'min_response_time',
    ])
        ->and($config['exclude_methods'])->toContain('OPTIONS')
        ->and($config['exclude_routes'])->toContain('health');
});

it('can access configuration through ApiLogger instance', function () {
    $logger = new ApiLogger(['level' => 'full', 'enabled' => false]);

    expect($logger->isEnabled())->toBe(false)
        ->and($logger->getLevel())->toBe('full')
        ->and($logger->config('level'))->toBe('full')
        ->and($logger->config('nonexistent', 'default'))->toBe('default');
});
