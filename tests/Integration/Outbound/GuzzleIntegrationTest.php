<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Integration\Outbound;

use Ameax\ApiLogger\Outbound\GuzzleLoggerMiddleware;
use Ameax\ApiLogger\Outbound\OutboundApiLogger;
use Ameax\ApiLogger\Outbound\OutboundFilterService;
use Ameax\ApiLogger\Services\DataSanitizer;
use Ameax\ApiLogger\StorageManager;
use Ameax\ApiLogger\Support\CorrelationIdManager;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Mockery;

beforeEach(function () {
    Config::set('apilogger', [
        'enabled' => true,
        'features' => [
            'outbound' => [
                'enabled' => true,
                'filters' => [
                    'exclude_hosts' => [],
                    'exclude_methods' => [],
                ],
            ],
        ],
        'privacy' => [
            'exclude_fields' => ['password', 'token'],
            'mask_fields' => ['email'],
        ],
    ]);
});

it('can log outbound requests through the full stack', function () {
    $capturedEntries = [];

    // Create a mock storage that captures entries
    $storageMock = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);
    $storageMock->shouldReceive('store')
        ->andReturnUsing(function ($entry) use (&$capturedEntries) {
            $capturedEntries[] = $entry;

            return true;
        });

    $storageManager = Mockery::mock(StorageManager::class);
    $storageManager->shouldReceive('store')
        ->andReturn($storageMock);

    $config = Config::get('apilogger');
    $dataSanitizer = new DataSanitizer($config);
    $filterService = new OutboundFilterService($config);
    $correlationIdManager = new CorrelationIdManager($config);
    $logger = new OutboundApiLogger($storageManager, $dataSanitizer, $filterService, $correlationIdManager, $config);

    $middleware = new GuzzleLoggerMiddleware($logger, $correlationIdManager);

    // Create Guzzle client with our middleware
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"success":true}'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($middleware);

    $client = new Client(['handler' => $handlerStack]);

    // Make a request
    $response = $client->post('https://api.example.com/users', [
        'json' => ['name' => 'John Doe', 'email' => 'john@example.com'],
        'headers' => ['Authorization' => 'Bearer secret-token'],
        'service_name' => 'UserService',
        'correlation_id' => 'main-123',
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(count($capturedEntries))->toBe(1);

    $entry = $capturedEntries[0];
    expect($entry->getMethod())->toBe('POST');
    expect($entry->getEndpoint())->toBe('https://api.example.com/users');
    expect($entry->getResponseCode())->toBe(200);
    expect($entry->getMetadata())->toHaveKey('direction', 'outbound');
    expect($entry->getMetadata())->toHaveKey('service_name', 'UserService');
    expect($entry->getMetadata())->toHaveKey('correlation_id', 'main-123');
});

it('sanitizes sensitive data in outbound requests', function () {
    $capturedEntries = [];

    $storageMock = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);
    $storageMock->shouldReceive('store')
        ->andReturnUsing(function ($entry) use (&$capturedEntries) {
            $capturedEntries[] = $entry;

            return true;
        });

    $storageManager = Mockery::mock(StorageManager::class);
    $storageManager->shouldReceive('store')
        ->andReturn($storageMock);

    $config = Config::get('apilogger');
    $dataSanitizer = new DataSanitizer($config);
    $filterService = new OutboundFilterService($config);
    $correlationIdManager = new CorrelationIdManager($config);
    $logger = new OutboundApiLogger($storageManager, $dataSanitizer, $filterService, $correlationIdManager, $config);

    $middleware = new GuzzleLoggerMiddleware($logger, $correlationIdManager);

    $mock = new MockHandler([
        new Response(200, [], '{"token":"response-token"}'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($middleware);

    $client = new Client(['handler' => $handlerStack]);

    $response = $client->post('https://api.example.com/auth', [
        'json' => [
            'email' => 'user@example.com',
            'password' => 'secret123',
            'token' => 'auth-token',
        ],
        'headers' => [
            'Authorization' => 'Bearer secret',
        ],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(count($capturedEntries))->toBe(1);

    $entry = $capturedEntries[0];

    // Check request body sanitization
    $requestBody = $entry->getRequestBody();
    if (is_string($requestBody)) {
        $requestBody = json_decode($requestBody, true);
    }
    expect($requestBody)->toBeArray();
    expect($requestBody['password'])->toBe('[REDACTED]');
    expect($requestBody['token'])->toBe('[REDACTED]');
    expect($requestBody['email'])->toMatch('/^[a-z]{2}\*+@\*+\.com$/');

    // Check response body sanitization
    $responseBody = $entry->getResponseBody();
    if (is_string($responseBody)) {
        $responseBody = json_decode($responseBody, true);
    }
    expect($responseBody)->toBeArray();
    expect($responseBody['token'])->toBe('[REDACTED]');

    // Check header sanitization
    $requestHeaders = $entry->getRequestHeaders();
    expect($requestHeaders['Authorization'])->toBe('[REDACTED]');
});
