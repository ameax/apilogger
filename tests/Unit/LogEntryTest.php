<?php

declare(strict_types=1);

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Carbon\Carbon;

it('creates a LogEntry with all properties', function () {
    $now = Carbon::now();
    $entry = new LogEntry(
        requestId: 'test-123',
        method: 'GET',
        endpoint: '/api/users',
        requestHeaders: ['Accept' => 'application/json'],
        requestBody: ['name' => 'John'],
        responseCode: 200,
        responseHeaders: ['Content-Type' => 'application/json'],
        responseBody: ['id' => 1, 'name' => 'John'],
        responseTimeMs: 150.5,
        userIdentifier: 'user-456',
        ipAddress: '192.168.1.1',
        userAgent: 'Mozilla/5.0',
        createdAt: $now,
        metadata: ['custom' => 'data'],
    );

    expect($entry->getRequestId())->toBe('test-123')
        ->and($entry->getMethod())->toBe('GET')
        ->and($entry->getEndpoint())->toBe('/api/users')
        ->and($entry->getRequestHeaders())->toBe(['Accept' => 'application/json'])
        ->and($entry->getRequestBody())->toBe(['name' => 'John'])
        ->and($entry->getResponseCode())->toBe(200)
        ->and($entry->getResponseHeaders())->toBe(['Content-Type' => 'application/json'])
        ->and($entry->getResponseBody())->toBe(['id' => 1, 'name' => 'John'])
        ->and($entry->getResponseTimeMs())->toBe(150.5)
        ->and($entry->getUserIdentifier())->toBe('user-456')
        ->and($entry->getIpAddress())->toBe('192.168.1.1')
        ->and($entry->getUserAgent())->toBe('Mozilla/5.0')
        ->and($entry->getCreatedAt())->toEqual($now)
        ->and($entry->getMetadata())->toBe(['custom' => 'data']);
});

it('converts LogEntry to array', function () {
    $entry = new LogEntry(
        requestId: 'test-123',
        method: 'POST',
        endpoint: '/api/users',
        requestHeaders: [],
        requestBody: null,
        responseCode: 201,
        responseHeaders: [],
        responseBody: null,
        responseTimeMs: 100.0,
    );

    $array = $entry->toArray();

    expect($array)->toBeArray()
        ->and($array['request_id'])->toBe('test-123')
        ->and($array['method'])->toBe('POST')
        ->and($array['endpoint'])->toBe('/api/users')
        ->and($array['response_code'])->toBe(201)
        ->and($array['response_time_ms'])->toBe(100.0)
        ->and($array)->toHaveKey('created_at');
});

it('converts LogEntry to JSON', function () {
    $entry = new LogEntry(
        requestId: 'test-123',
        method: 'GET',
        endpoint: '/api/test',
        requestHeaders: [],
        requestBody: null,
        responseCode: 200,
        responseHeaders: [],
        responseBody: ['success' => true],
        responseTimeMs: 50.0,
    );

    $json = $entry->toJson();
    $decoded = json_decode($json, true);

    expect($json)->toBeString()
        ->and($decoded['request_id'])->toBe('test-123')
        ->and($decoded['response_body'])->toBe(['success' => true]);
});

it('creates LogEntry from array', function () {
    $data = [
        'request_id' => 'test-456',
        'method' => 'PUT',
        'endpoint' => '/api/users/1',
        'request_headers' => ['Content-Type' => 'application/json'],
        'request_body' => ['name' => 'Jane'],
        'response_code' => 200,
        'response_headers' => [],
        'response_body' => ['updated' => true],
        'response_time_ms' => 75.5,
        'user_identifier' => 'user-789',
        'ip_address' => '10.0.0.1',
        'created_at' => '2024-01-01T12:00:00+00:00',
    ];

    $entry = LogEntry::fromArray($data);

    expect($entry->getRequestId())->toBe('test-456')
        ->and($entry->getMethod())->toBe('PUT')
        ->and($entry->getResponseTimeMs())->toBe(75.5)
        ->and($entry->getUserIdentifier())->toBe('user-789');
});

it('correctly identifies response types', function () {
    $successEntry = new LogEntry(
        requestId: 'test-1',
        method: 'GET',
        endpoint: '/api/test',
        requestHeaders: [],
        requestBody: null,
        responseCode: 200,
        responseHeaders: [],
        responseBody: null,
        responseTimeMs: 10.0,
    );

    $clientErrorEntry = new LogEntry(
        requestId: 'test-2',
        method: 'GET',
        endpoint: '/api/test',
        requestHeaders: [],
        requestBody: null,
        responseCode: 404,
        responseHeaders: [],
        responseBody: null,
        responseTimeMs: 10.0,
    );

    $serverErrorEntry = new LogEntry(
        requestId: 'test-3',
        method: 'GET',
        endpoint: '/api/test',
        requestHeaders: [],
        requestBody: null,
        responseCode: 500,
        responseHeaders: [],
        responseBody: null,
        responseTimeMs: 10.0,
    );

    expect($successEntry->isSuccess())->toBeTrue()
        ->and($successEntry->isError())->toBeFalse()
        ->and($clientErrorEntry->isClientError())->toBeTrue()
        ->and($clientErrorEntry->isError())->toBeTrue()
        ->and($serverErrorEntry->isServerError())->toBeTrue()
        ->and($serverErrorEntry->isError())->toBeTrue();
});
