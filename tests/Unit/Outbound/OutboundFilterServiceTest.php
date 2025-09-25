<?php

declare(strict_types=1);

use Ameax\ApiLogger\Outbound\OutboundFilterService;
use GuzzleHttp\Psr7\Request;

beforeEach(function () {
    $this->config = [
        'features' => [
            'outbound' => [
                'enabled' => true,
                'filters' => [
                    'include_hosts' => [],
                    'exclude_hosts' => ['localhost', '127.0.0.1'],
                    'include_methods' => [],
                    'exclude_methods' => ['OPTIONS'],
                    'cache_enabled' => false,
                ],
            ],
        ],
    ];
});

it('returns false when outbound logging is disabled', function () {
    $config = array_replace_recursive($this->config, [
        'features' => ['outbound' => ['enabled' => false]],
    ]);

    $service = new OutboundFilterService($config);
    $request = new Request('GET', 'https://api.example.com');

    expect($service->shouldLog($request))->toBeFalse();
});

it('excludes requests to localhost', function () {
    $service = new OutboundFilterService($this->config);
    $request = new Request('GET', 'http://localhost/api/test');

    expect($service->shouldLog($request))->toBeFalse();
});

it('excludes requests with OPTIONS method', function () {
    $service = new OutboundFilterService($this->config);
    $request = new Request('OPTIONS', 'https://api.example.com');

    expect($service->shouldLog($request))->toBeFalse();
});

it('logs requests not in exclude list', function () {
    $service = new OutboundFilterService($this->config);
    $request = new Request('GET', 'https://api.example.com');

    expect($service->shouldLog($request))->toBeTrue();
});

it('respects include hosts when specified', function () {
    $config = array_replace_recursive($this->config, [
        'features' => [
            'outbound' => [
                'filters' => [
                    'include_hosts' => ['api.example.com', '*.stripe.com'],
                ],
            ],
        ],
    ]);

    $service = new OutboundFilterService($config);

    $allowedRequest = new Request('GET', 'https://api.example.com/test');
    expect($service->shouldLog($allowedRequest))->toBeTrue();

    $wildcardRequest = new Request('GET', 'https://api.stripe.com/test');
    expect($service->shouldLog($wildcardRequest))->toBeTrue();

    $notAllowedRequest = new Request('GET', 'https://other.api.com/test');
    expect($service->shouldLog($notAllowedRequest))->toBeFalse();
});

it('supports wildcard patterns in hosts', function () {
    $config = array_replace_recursive($this->config, [
        'features' => [
            'outbound' => [
                'filters' => [
                    'exclude_hosts' => ['*.local', 'test-*'],
                ],
            ],
        ],
    ]);

    $service = new OutboundFilterService($config);

    $localRequest = new Request('GET', 'https://api.local/test');
    expect($service->shouldLog($localRequest))->toBeFalse();

    $testRequest = new Request('GET', 'https://test-api.com/test');
    expect($service->shouldLog($testRequest))->toBeFalse();

    $normalRequest = new Request('GET', 'https://api.example.com/test');
    expect($service->shouldLog($normalRequest))->toBeTrue();
});

it('supports regex patterns in URL paths', function () {
    $config = array_replace_recursive($this->config, [
        'features' => [
            'outbound' => [
                'filters' => [
                    'exclude_patterns' => ['/^\/health/', '/metrics$/'],
                ],
            ],
        ],
    ]);

    $service = new OutboundFilterService($config);

    $healthRequest = new Request('GET', 'https://api.example.com/health/check');
    expect($service->shouldLog($healthRequest))->toBeFalse();

    $metricsRequest = new Request('GET', 'https://api.example.com/api/metrics');
    expect($service->shouldLog($metricsRequest))->toBeFalse();

    $normalRequest = new Request('GET', 'https://api.example.com/api/users');
    expect($service->shouldLog($normalRequest))->toBeTrue();
});

it('prioritizes exclude filters over include filters', function () {
    $config = array_replace_recursive($this->config, [
        'features' => [
            'outbound' => [
                'filters' => [
                    'include_hosts' => ['api.example.com'],
                    'exclude_hosts' => ['api.example.com'],
                ],
            ],
        ],
    ]);

    $service = new OutboundFilterService($config);
    $request = new Request('GET', 'https://api.example.com/test');

    expect($service->shouldLog($request))->toBeFalse();
});

it('filters by service class', function () {
    $config = array_replace_recursive($this->config, [
        'features' => [
            'outbound' => [
                'filters' => [
                    'include_services' => ['App\\Services\\PaymentService'],
                    'exclude_services' => ['App\\Services\\CacheService'],
                ],
            ],
        ],
    ]);

    $service = new OutboundFilterService($config);
    $request = new Request('GET', 'https://api.example.com');

    // Included service
    expect($service->shouldLog($request, 'App\\Services\\PaymentService'))->toBeTrue();

    // Excluded service (exclude takes priority)
    expect($service->shouldLog($request, 'App\\Services\\CacheService'))->toBeFalse();

    // Not mentioned service (not in include list)
    expect($service->shouldLog($request, 'App\\Services\\OtherService'))->toBeFalse();
});

it('retrieves service-specific configuration', function () {
    $config = array_replace_recursive($this->config, [
        'features' => [
            'outbound' => [
                'log_level' => 'basic',
                'services' => [
                    'configs' => [
                        'App\\Services\\PaymentService' => [
                            'log_level' => 'full',
                            'always_log_errors' => false,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $service = new OutboundFilterService($config);

    $serviceConfig = $service->getServiceConfig('App\\Services\\PaymentService');
    expect($serviceConfig['log_level'])->toBe('full');
    expect($serviceConfig['always_log_errors'])->toBeFalse();

    // Should return global config for unknown service
    $unknownConfig = $service->getServiceConfig('App\\Services\\UnknownService');
    expect($unknownConfig['log_level'])->toBe('basic');
});

it('checks if errors should always be logged for a service', function () {
    $config = array_replace_recursive($this->config, [
        'features' => [
            'outbound' => [
                'always_log_errors' => true,
                'services' => [
                    'configs' => [
                        'App\\Services\\PaymentService' => [
                            'always_log_errors' => false,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $service = new OutboundFilterService($config);

    expect($service->shouldLogErrors('App\\Services\\PaymentService'))->toBeFalse();
    expect($service->shouldLogErrors('App\\Services\\OtherService'))->toBeTrue();
});
