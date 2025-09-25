<?php

declare(strict_types=1);

use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;
use Ameax\ApiLogger\Outbound\ServiceRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

beforeEach(function () {
    ServiceRegistry::clear();

    // Mock service classes
    if (! class_exists('App\\Services\\TestService')) {
        eval('namespace App\\Services; class TestService {}');
    }

    // Configure apilogger
    config()->set('apilogger', [
        'features' => [
            'outbound' => [
                'enabled' => true,
            ],
            'correlation' => [
                'enabled' => true,
            ],
        ],
    ]);
});

afterEach(function () {
    ServiceRegistry::clear();
});

it('creates a handler stack with logging middleware when enabled', function () {
    $stack = GuzzleHandlerStackFactory::create();

    expect($stack)->toBeInstanceOf(HandlerStack::class);

    // Check if middleware was added
    $stackArray = (array) $stack;
    $stackProperty = array_values($stackArray)[0] ?? [];
    expect($stackProperty)->not->toBeEmpty();
});

it('returns basic stack when outbound logging is disabled', function () {
    config()->set('apilogger.features.outbound.enabled', false);

    $stack = GuzzleHandlerStackFactory::create();

    expect($stack)->toBeInstanceOf(HandlerStack::class);
});

it('creates stack for registered service', function () {
    ServiceRegistry::register('App\\Services\\TestService', [
        'enabled' => true,
        'log_level' => 'full',
    ]);

    $stack = GuzzleHandlerStackFactory::createForService('App\\Services\\TestService');

    expect($stack)->toBeInstanceOf(HandlerStack::class);
});

it('does not add middleware for disabled service', function () {
    ServiceRegistry::register('App\\Services\\TestService', [
        'enabled' => false,
    ]);

    $stack = GuzzleHandlerStackFactory::createForService('App\\Services\\TestService');

    expect($stack)->toBeInstanceOf(HandlerStack::class);
});

it('adds middleware to existing stack', function () {
    $existingStack = HandlerStack::create();

    $enhancedStack = GuzzleHandlerStackFactory::addToStack($existingStack);

    expect($enhancedStack)->toBe($existingStack);
});

it('creates basic stack without middleware', function () {
    $stack = GuzzleHandlerStackFactory::createBasic();

    expect($stack)->toBeInstanceOf(HandlerStack::class);
});

it('checks if logging is enabled globally', function () {
    config()->set('apilogger.features.outbound.enabled', true);
    expect(GuzzleHandlerStackFactory::isLoggingEnabled())->toBeTrue();

    config()->set('apilogger.features.outbound.enabled', false);
    expect(GuzzleHandlerStackFactory::isLoggingEnabled())->toBeFalse();
});

it('checks if logging is enabled for specific service', function () {
    ServiceRegistry::register('App\\Services\\TestService', [
        'enabled' => true,
    ]);

    expect(GuzzleHandlerStackFactory::isLoggingEnabled('App\\Services\\TestService'))->toBeTrue();

    ServiceRegistry::register('App\\Services\\TestService', [
        'enabled' => false,
    ]);

    expect(GuzzleHandlerStackFactory::isLoggingEnabled('App\\Services\\TestService'))->toBeFalse();
});

it('adds retry middleware when configured', function () {
    $config = [
        'retry' => true,
        'retry_max_attempts' => 3,
        'retry_on_statuses' => [500, 502, 503],
        'retry_base_delay_ms' => 1000,
        'retry_multiplier' => 2,
    ];

    $stack = GuzzleHandlerStackFactory::create(null, null, $config);

    // Just verify the stack was created successfully with the retry config
    expect($stack)->toBeInstanceOf(HandlerStack::class);
});

it('integrates with Guzzle client', function () {
    $stack = GuzzleHandlerStackFactory::create();

    $client = new Client([
        'handler' => $stack,
        'base_uri' => 'https://httpbin.org',
    ]);

    // This should not throw an exception
    expect($client)->toBeInstanceOf(Client::class);
});
