<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Unit\Outbound;

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Outbound\OutboundApiLogger;
use Ameax\ApiLogger\Outbound\OutboundFilterService;
use Ameax\ApiLogger\Services\DataSanitizer;
use Ameax\ApiLogger\StorageManager;
use Ameax\ApiLogger\Support\CorrelationIdManager;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;

beforeEach(function () {
    $this->storageManager = Mockery::mock(StorageManager::class);
    $this->dataSanitizer = Mockery::mock(DataSanitizer::class);

    $this->config = [
        'enabled' => true,
        'features' => [
            'outbound' => [
                'enabled' => true,
                'filters' => [
                    'exclude_hosts' => ['internal.example.com', '*.local'],
                    'exclude_methods' => ['OPTIONS', 'HEAD'],
                ],
            ],
        ],
    ];

    $this->filterService = new OutboundFilterService($this->config);
    $this->correlationIdManager = new CorrelationIdManager($this->config);

    $this->logger = new OutboundApiLogger(
        $this->storageManager,
        $this->dataSanitizer,
        $this->filterService,
        $this->correlationIdManager,
        $this->config
    );
});

afterEach(function () {
    Mockery::close();
});

it('generates unique request IDs', function () {
    $request = new Request('GET', 'https://api.example.com/test');
    $options = [];

    $requestId1 = $this->logger->logRequest($request, $options);
    $requestId2 = $this->logger->logRequest($request, $options);

    expect($requestId1)->toBeString();
    expect($requestId2)->toBeString();
    expect($requestId1)->not->toBe($requestId2);
});

it('logs successful responses', function () {
    $requestId = 'test-request-id';
    $request = new Request('POST', 'https://api.example.com/users', [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer token123',
    ], '{"name":"John"}');

    $response = new Response(201, [
        'Content-Type' => 'application/json',
    ], '{"id":1,"name":"John"}');

    $options = [
        'service_name' => 'UserService',
        'correlation_id' => 'corr-123',
    ];

    $this->dataSanitizer->shouldReceive('sanitizeBody')
        ->with(['name' => 'John'])
        ->once()
        ->andReturn(['name' => 'John']);

    $this->dataSanitizer->shouldReceive('sanitizeBody')
        ->with(['id' => 1, 'name' => 'John'])
        ->once()
        ->andReturn(['id' => 1, 'name' => 'John']);

    $this->dataSanitizer->shouldReceive('sanitizeHeaders')
        ->twice()
        ->andReturnUsing(fn ($headers) => $headers);

    $this->dataSanitizer->shouldReceive('sanitizeQueryParams')
        ->with([])
        ->once()
        ->andReturn([]);

    $storageInstance = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);
    $this->storageManager->shouldReceive('store')
        ->once()
        ->andReturn($storageInstance);

    $storageInstance->shouldReceive('store')
        ->once()
        ->with(Mockery::on(function (LogEntry $entry) use ($requestId) {
            expect($entry->getRequestId())->toBe($requestId);
            expect($entry->getMethod())->toBe('POST');
            expect($entry->getEndpoint())->toBe('https://api.example.com/users');
            expect($entry->getResponseCode())->toBe(201);
            expect($entry->getMetadata())->toHaveKey('direction', 'outbound');
            expect($entry->getMetadata())->toHaveKey('service_name', 'UserService');
            expect($entry->getMetadata())->toHaveKey('correlation_id', 'corr-123');

            return true;
        }));

    $this->logger->logResponse($requestId, $request, $response, 150.5, $options);
});

it('logs error responses', function () {
    $requestId = 'test-request-id';
    $request = new Request('GET', 'https://api.example.com/error');
    $response = new Response(500, [], '{"error":"Internal Server Error"}');

    // Request has no body, so sanitizeBody won't be called for request
    $this->dataSanitizer->shouldReceive('sanitizeBody')
        ->with(['error' => 'Internal Server Error'])
        ->once()
        ->andReturn(['error' => 'Internal Server Error']);

    $this->dataSanitizer->shouldReceive('sanitizeHeaders')
        ->twice()
        ->andReturnUsing(fn ($headers) => $headers);

    $this->dataSanitizer->shouldReceive('sanitizeQueryParams')
        ->with([])
        ->once()
        ->andReturn([]);

    $storageInstance = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);
    $this->storageManager->shouldReceive('store')
        ->once()
        ->andReturn($storageInstance);

    $storageInstance->shouldReceive('store')
        ->once()
        ->with(Mockery::on(function (LogEntry $entry) {
            expect($entry->getResponseCode())->toBe(500);
            expect($entry->getResponseBody())->toBe(['error' => 'Internal Server Error']);

            return true;
        }));

    $this->logger->logResponse($requestId, $request, $response, 100.0, []);
});

it('logs exceptions without response', function () {
    $requestId = 'test-request-id';
    $request = new Request('GET', 'https://api.example.com/timeout');
    $error = new \Exception('Connection timeout', 408);

    // Request has no body and response is null, so sanitizeBody won't be called at all

    $this->dataSanitizer->shouldReceive('sanitizeHeaders')
        ->twice()
        ->andReturnUsing(fn ($headers) => $headers);

    $this->dataSanitizer->shouldReceive('sanitizeQueryParams')
        ->with([])
        ->once()
        ->andReturn([]);

    $storageInstance = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);
    $this->storageManager->shouldReceive('store')
        ->once()
        ->andReturn($storageInstance);

    $storageInstance->shouldReceive('store')
        ->once()
        ->with(Mockery::on(function (LogEntry $entry) {
            expect($entry->getResponseCode())->toBe(500);
            expect($entry->getResponseBody())->toBeNull();
            expect($entry->getMetadata())->toHaveKey('error');
            expect($entry->getMetadata()['error'])->toHaveKey('type', 'Exception');
            expect($entry->getMetadata()['error'])->toHaveKey('message', 'Connection timeout');

            return true;
        }));

    $this->logger->logResponse($requestId, $request, null, 30000.0, [], $error);
});

it('extracts metadata from request and options', function () {
    $request = new Request('GET', 'https://api.example.com:8080/users?page=1&limit=10');
    $options = [
        'service_name' => 'UserAPI',
        'service' => 'UserService',
        'correlation_id' => 'main-req-123',
        'retry_attempt' => 2,
        'timeout' => 30,
    ];

    $metadata = $this->logger->extractMetadata($request, $options);

    expect($metadata)->toHaveKey('host', 'api.example.com');
    expect($metadata)->toHaveKey('port', 8080);
    expect($metadata)->toHaveKey('scheme', 'https');
    expect($metadata)->toHaveKey('path', '/users');
    expect($metadata)->toHaveKey('query', 'page=1&limit=10');
    expect($metadata)->toHaveKey('service_name', 'UserAPI');
    expect($metadata)->toHaveKey('service', 'UserService');
    expect($metadata)->toHaveKey('correlation_id', 'main-req-123');
    expect($metadata)->toHaveKey('retry_attempt', 2);
    expect($metadata)->toHaveKey('timeout', 30);
    expect($metadata)->toHaveKey('environment');
});

it('respects outbound logging enabled flag', function () {
    $config = array_merge($this->config, ['features' => ['outbound' => ['enabled' => false]]]);
    $filterService = new OutboundFilterService($config);
    $correlationIdManager = new CorrelationIdManager($config);
    $logger = new OutboundApiLogger($this->storageManager, $this->dataSanitizer, $filterService, $correlationIdManager, $config);

    $request = new Request('GET', 'https://api.example.com/test');

    expect($logger->shouldLog($request, []))->toBeFalse();

    $this->storageManager->shouldNotReceive('store');

    $logger->logResponse('request-id', $request, new Response(200), 100.0, []);
});

it('respects global enabled flag', function () {
    $config = array_merge($this->config, ['features' => ['outbound' => ['enabled' => false]]]);
    $filterService = new OutboundFilterService($config);
    $correlationIdManager = new CorrelationIdManager($config);
    $logger = new OutboundApiLogger($this->storageManager, $this->dataSanitizer, $filterService, $correlationIdManager, $config);

    $request = new Request('GET', 'https://api.example.com/test');

    expect($logger->shouldLog($request, []))->toBeFalse();
});

it('excludes configured hosts', function () {
    $request1 = new Request('GET', 'https://internal.example.com/api');
    $request2 = new Request('GET', 'https://service.local/api');
    $request3 = new Request('GET', 'https://api.external.com/api');

    expect($this->logger->shouldLog($request1, []))->toBeFalse();
    expect($this->logger->shouldLog($request2, []))->toBeFalse();
    expect($this->logger->shouldLog($request3, []))->toBeTrue();
});

it('excludes configured methods', function () {
    $request1 = new Request('OPTIONS', 'https://api.example.com/test');
    $request2 = new Request('HEAD', 'https://api.example.com/test');
    $request3 = new Request('GET', 'https://api.example.com/test');

    expect($this->logger->shouldLog($request1, []))->toBeFalse();
    expect($this->logger->shouldLog($request2, []))->toBeFalse();
    expect($this->logger->shouldLog($request3, []))->toBeTrue();
});

it('respects log_disabled option', function () {
    $request = new Request('GET', 'https://api.example.com/test');

    expect($this->logger->shouldLog($request, ['log_disabled' => true]))->toBeFalse();
    expect($this->logger->shouldLog($request, ['log_disabled' => false]))->toBeTrue();
    expect($this->logger->shouldLog($request, []))->toBeTrue();
});

it('handles multipart headers correctly', function () {
    $request = new Request('POST', 'https://api.example.com/upload', [
        'Content-Type' => 'multipart/form-data; boundary=----WebKitFormBoundary',
    ]);

    $response = new Response(200, [
        'Set-Cookie' => ['session=abc123', 'tracking=xyz789'],
    ]);

    $this->dataSanitizer->shouldReceive('sanitizeBody')->andReturn('');
    $this->dataSanitizer->shouldReceive('sanitizeHeaders')
        ->twice()
        ->andReturnUsing(function ($headers) {
            // Request headers have Content-Type, response headers have Set-Cookie
            if (isset($headers['Content-Type'])) {
                expect($headers['Content-Type'])->toBeString();
            }
            if (isset($headers['Set-Cookie'])) {
                expect($headers['Set-Cookie'])->toBeString();
            }

            return $headers;
        });

    $this->dataSanitizer->shouldReceive('sanitizeQueryParams')
        ->with([])
        ->once()
        ->andReturn([]);

    $storageInstance = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);
    $this->storageManager->shouldReceive('store')
        ->once()
        ->andReturn($storageInstance);

    $storageInstance->shouldReceive('store')
        ->once();

    $this->logger->logResponse('request-id', $request, $response, 100.0, []);
});

it('preserves request and response body streams', function () {
    $requestBody = '{"test":"data"}';
    $responseBody = '{"result":"success"}';

    $request = new Request('POST', 'https://api.example.com/test', [], $requestBody);
    $response = new Response(200, [], $responseBody);

    $this->dataSanitizer->shouldReceive('sanitizeBody')->andReturnUsing(fn ($body) => $body);
    $this->dataSanitizer->shouldReceive('sanitizeHeaders')->andReturnUsing(fn ($headers) => $headers);
    $this->dataSanitizer->shouldReceive('sanitizeQueryParams')
        ->with([])
        ->once()
        ->andReturn([]);

    $storageInstance = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);
    $this->storageManager->shouldReceive('store')
        ->once()
        ->andReturn($storageInstance);

    $storageInstance->shouldReceive('store')->once();

    $this->logger->logResponse('request-id', $request, $response, 100.0, []);

    expect((string) $request->getBody())->toBe($requestBody);
    expect((string) $response->getBody())->toBe($responseBody);
});
