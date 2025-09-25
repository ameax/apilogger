<?php

declare(strict_types=1);

use Ameax\ApiLogger\Support\CorrelationIdManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->config = [
        'features' => [
            'correlation' => [
                'enabled' => true,
                'header_name' => 'X-Correlation-ID',
                'headers' => ['X-Correlation-ID', 'X-Request-ID'],
                'propagate' => true,
                'add_to_response' => true,
                'generation_method' => 'uuid',
            ],
        ],
    ];

    // Reset static property
    $reflection = new ReflectionClass(CorrelationIdManager::class);
    $property = $reflection->getProperty('currentCorrelationId');
    $property->setAccessible(true);
    $property->setValue(null, null);
});

it('generates a UUID correlation ID by default', function () {
    $manager = new CorrelationIdManager($this->config);
    $id = $manager->generate();

    expect($id)->toBeString();
    expect(Str::isUuid($id))->toBeTrue();
});

it('generates a ULID when configured', function () {
    $config = array_replace_recursive($this->config, [
        'features' => ['correlation' => ['generation_method' => 'ulid']],
    ]);

    $manager = new CorrelationIdManager($config);
    $id = $manager->generate();

    expect($id)->toBeString();
    expect(strlen($id))->toBe(26); // ULIDs are 26 characters
});

it('generates a timestamp-based ID when configured', function () {
    $config = array_replace_recursive($this->config, [
        'features' => ['correlation' => ['generation_method' => 'timestamp']],
    ]);

    $manager = new CorrelationIdManager($config);
    $id = $manager->generate();

    expect($id)->toBeString();
    expect($id)->toContain('-');
    expect(explode('-', $id))->toHaveCount(2);
});

it('extracts correlation ID from request headers', function () {
    $manager = new CorrelationIdManager($this->config);

    $request = Request::create('/test');
    $request->headers->set('X-Correlation-ID', 'test-correlation-id');

    $extracted = $manager->extractFromRequest($request);
    expect($extracted)->toBe('test-correlation-id');
});

it('tries multiple headers to find correlation ID', function () {
    $manager = new CorrelationIdManager($this->config);

    $request = Request::create('/test');
    $request->headers->set('X-Request-ID', 'test-request-id');

    $extracted = $manager->extractFromRequest($request);
    expect($extracted)->toBe('test-request-id');
});

it('returns null when no correlation ID in request', function () {
    $manager = new CorrelationIdManager($this->config);

    $request = Request::create('/test');

    $extracted = $manager->extractFromRequest($request);
    expect($extracted)->toBeNull();
});

it('extracts correlation ID from array of headers', function () {
    $manager = new CorrelationIdManager($this->config);

    $headers = [
        'Content-Type' => 'application/json',
        'X-Correlation-ID' => 'array-correlation-id',
    ];

    $extracted = $manager->extractFromArray($headers);
    expect($extracted)->toBe('array-correlation-id');
});

it('handles array values in headers', function () {
    $manager = new CorrelationIdManager($this->config);

    $headers = [
        'X-Correlation-ID' => ['first-id', 'second-id'],
    ];

    $extracted = $manager->extractFromArray($headers);
    expect($extracted)->toBe('first-id');
});

it('maintains correlation ID across calls', function () {
    $manager = new CorrelationIdManager($this->config);

    $id1 = $manager->getCorrelationId();
    $id2 = $manager->getCorrelationId();

    expect($id1)->toBe($id2);
});

it('can set correlation ID manually', function () {
    $manager = new CorrelationIdManager($this->config);

    $manager->setCorrelationId('manual-id');
    expect($manager->getCorrelationId())->toBe('manual-id');
});

it('resets correlation ID', function () {
    $manager = new CorrelationIdManager($this->config);

    $id1 = $manager->getCorrelationId();
    $manager->reset();

    // Create new manager to get fresh ID
    $manager2 = new CorrelationIdManager($this->config);
    $id2 = $manager2->getCorrelationId();

    expect($id1)->not->toBe($id2);
});

it('adds correlation ID to headers when propagation is enabled', function () {
    $manager = new CorrelationIdManager($this->config);
    $manager->setCorrelationId('test-id');

    $headers = [];
    $manager->addToHeaders($headers);

    expect($headers)->toHaveKey('X-Correlation-ID');
    expect($headers['X-Correlation-ID'])->toBe('test-id');
});

it('does not add correlation ID when propagation is disabled', function () {
    $config = array_replace_recursive($this->config, [
        'features' => ['correlation' => ['propagate' => false]],
    ]);

    $manager = new CorrelationIdManager($config);
    $manager->setCorrelationId('test-id');

    $headers = [];
    $manager->addToHeaders($headers);

    expect($headers)->toBeEmpty();
});

it('uses configured header name', function () {
    $config = array_replace_recursive($this->config, [
        'features' => ['correlation' => ['header_name' => 'X-Custom-ID']],
    ]);

    $manager = new CorrelationIdManager($config);
    expect($manager->getHeaderName())->toBe('X-Custom-ID');
});

it('checks if correlation is enabled', function () {
    $manager = new CorrelationIdManager($this->config);
    expect($manager->isEnabled())->toBeTrue();

    $config = array_replace_recursive($this->config, [
        'features' => ['correlation' => ['enabled' => false]],
    ]);

    $manager2 = new CorrelationIdManager($config);
    expect($manager2->isEnabled())->toBeFalse();
});

it('can access current correlation ID statically', function () {
    $manager = new CorrelationIdManager($this->config);
    $manager->setCorrelationId('static-test-id');

    expect(CorrelationIdManager::current())->toBe('static-test-id');
});
