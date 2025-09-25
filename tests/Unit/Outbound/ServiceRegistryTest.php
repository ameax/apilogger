<?php

declare(strict_types=1);

use Ameax\ApiLogger\Outbound\ServiceRegistry;

beforeEach(function () {
    ServiceRegistry::clear();

    // Create mock service classes
    if (! class_exists('App\\Services\\PaymentService')) {
        eval('namespace App\\Services; class PaymentService {}');
    }
    if (! class_exists('App\\Services\\EmailService')) {
        eval('namespace App\\Services; class EmailService {}');
    }
});

afterEach(function () {
    ServiceRegistry::clear();
});

it('can register a service', function () {
    ServiceRegistry::register('App\\Services\\PaymentService', [
        'enabled' => true,
        'log_level' => 'full',
    ]);

    expect(ServiceRegistry::isRegistered('App\\Services\\PaymentService'))->toBeTrue();
    expect(ServiceRegistry::count())->toBe(1);
});

it('can unregister a service', function () {
    ServiceRegistry::register('App\\Services\\PaymentService');
    expect(ServiceRegistry::isRegistered('App\\Services\\PaymentService'))->toBeTrue();

    ServiceRegistry::unregister('App\\Services\\PaymentService');
    expect(ServiceRegistry::isRegistered('App\\Services\\PaymentService'))->toBeFalse();
});

it('can get service configuration', function () {
    $config = [
        'enabled' => false,
        'log_level' => 'basic',
        'hosts' => ['api.payment.com'],
    ];

    ServiceRegistry::register('App\\Services\\PaymentService', $config);

    $retrievedConfig = ServiceRegistry::getConfig('App\\Services\\PaymentService');
    expect($retrievedConfig)->toBe($config);
});

it('returns null for unregistered service config', function () {
    expect(ServiceRegistry::getConfig('App\\Services\\UnknownService'))->toBeNull();
});

it('can get service metadata', function () {
    ServiceRegistry::register('App\\Services\\PaymentService', [], [
        'name' => 'Payment Gateway',
        'version' => '2.0',
    ]);

    $metadata = ServiceRegistry::getMetadata('App\\Services\\PaymentService');
    expect($metadata['name'])->toBe('Payment Gateway');
    expect($metadata['version'])->toBe('2.0');
    expect($metadata['class'])->toBe('App\\Services\\PaymentService');
    expect($metadata)->toHaveKey('registered_at');
});

it('can get all registered services', function () {
    ServiceRegistry::register('App\\Services\\PaymentService');
    ServiceRegistry::register('App\\Services\\EmailService');

    $services = ServiceRegistry::getAllServices();
    expect($services)->toHaveCount(2);
    expect($services)->toContain('App\\Services\\PaymentService');
    expect($services)->toContain('App\\Services\\EmailService');
});

it('determines if a service should be logged', function () {
    ServiceRegistry::register('App\\Services\\PaymentService', ['enabled' => true]);
    ServiceRegistry::register('App\\Services\\EmailService', ['enabled' => false]);

    expect(ServiceRegistry::shouldLog('App\\Services\\PaymentService'))->toBeTrue();
    expect(ServiceRegistry::shouldLog('App\\Services\\EmailService'))->toBeFalse();
    expect(ServiceRegistry::shouldLog('App\\Services\\UnknownService'))->toBeFalse();
});

it('gets service name from config or metadata', function () {
    ServiceRegistry::register('App\\Services\\PaymentService', [
        'name' => 'Payment API',
    ]);

    ServiceRegistry::register('App\\Services\\EmailService', [], [
        'name' => 'Email Gateway',
    ]);

    expect(ServiceRegistry::getServiceName('App\\Services\\PaymentService'))->toBe('Payment API');
    expect(ServiceRegistry::getServiceName('App\\Services\\EmailService'))->toBe('Email Gateway');
});

it('falls back to simple class name when no name is configured', function () {
    ServiceRegistry::register('App\\Services\\PaymentService');

    expect(ServiceRegistry::getServiceName('App\\Services\\PaymentService'))->toBe('PaymentService');
});

it('can find service by host', function () {
    ServiceRegistry::register('App\\Services\\PaymentService', [
        'hosts' => ['api.payment.com', '*.stripe.com'],
    ]);

    expect(ServiceRegistry::findServiceByHost('api.payment.com'))->toBe('App\\Services\\PaymentService');
    expect(ServiceRegistry::findServiceByHost('api.stripe.com'))->toBe('App\\Services\\PaymentService');
    expect(ServiceRegistry::findServiceByHost('checkout.stripe.com'))->toBe('App\\Services\\PaymentService');
    expect(ServiceRegistry::findServiceByHost('api.other.com'))->toBeNull();
});

it('gets service-specific log level', function () {
    ServiceRegistry::register('App\\Services\\PaymentService', [
        'log_level' => 'detailed',
    ]);

    expect(ServiceRegistry::getLogLevel('App\\Services\\PaymentService'))->toBe('detailed');
});

it('gets service-specific sanitize fields', function () {
    ServiceRegistry::register('App\\Services\\PaymentService', [
        'sanitize_fields' => ['card_number', 'cvv'],
    ]);

    expect(ServiceRegistry::getSanitizeFields('App\\Services\\PaymentService'))->toBe(['card_number', 'cvv']);
});

it('gets service timeout configuration', function () {
    ServiceRegistry::register('App\\Services\\PaymentService', [
        'timeout_ms' => 5000,
    ]);

    expect(ServiceRegistry::getTimeout('App\\Services\\PaymentService'))->toBe(5000);
});

it('merges service config with global config', function () {
    $globalConfig = [
        'log_level' => 'basic',
        'timeout_ms' => 3000,
        'always_log_errors' => true,
    ];

    ServiceRegistry::register('App\\Services\\PaymentService', [
        'log_level' => 'full',
        'always_log_errors' => false,
    ]);

    $merged = ServiceRegistry::mergeWithGlobalConfig('App\\Services\\PaymentService', $globalConfig);

    expect($merged['log_level'])->toBe('full'); // Service overrides
    expect($merged['timeout_ms'])->toBe(3000); // From global
    expect($merged['always_log_errors'])->toBeFalse(); // Service overrides
});

it('can export and import registry state', function () {
    ServiceRegistry::register('App\\Services\\PaymentService', [
        'enabled' => true,
    ], [
        'name' => 'Payment API',
    ]);

    $state = ServiceRegistry::toArray();
    expect($state)->toHaveKeys(['services', 'metadata']);

    ServiceRegistry::clear();
    expect(ServiceRegistry::count())->toBe(0);

    ServiceRegistry::fromArray($state);
    expect(ServiceRegistry::count())->toBe(1);
    expect(ServiceRegistry::isRegistered('App\\Services\\PaymentService'))->toBeTrue();
});
