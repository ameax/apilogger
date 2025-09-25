<?php

declare(strict_types=1);

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Models\ApiLog;
use Ameax\ApiLogger\Services\DataSanitizer;
use Ameax\ApiLogger\Services\RequestCapture;
use Ameax\ApiLogger\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('apilogger.enabled', true);
    Config::set('apilogger.level', 'full');
    Config::set('apilogger.storage.driver', 'database');
});

it('captures URL parameters from GET requests', function () {
    $request = Request::create('/api/users?page=1&per_page=10&sort=name', 'GET');

    $capture = new RequestCapture(config('apilogger'));
    $data = $capture->capture($request);

    expect($data['query_params'])->toBe([
        'page' => '1',
        'per_page' => '10',
        'sort' => 'name',
    ]);
});

it('stores URL parameters in the database', function () {
    $logEntry = new LogEntry(
        requestId: 'test-123',
        method: 'GET',
        endpoint: '/api/users',
        requestHeaders: [],
        requestBody: null,
        responseCode: 200,
        responseHeaders: [],
        responseBody: ['users' => []],
        responseTimeMs: 50.0,
        requestParameters: ['page' => 1, 'limit' => 10],
    );

    $storageManager = app(StorageManager::class);
    $stored = $storageManager->driver()->store($logEntry);

    expect($stored)->toBeTrue();

    $apiLog = ApiLog::where('correlation_identifier', 'test-123')->first();
    expect($apiLog)->not->toBeNull();
    expect($apiLog->request_parameters)->toBe(['page' => 1, 'limit' => 10]);
});

it('sanitizes sensitive URL parameters', function () {
    $sanitizer = new DataSanitizer(config('apilogger'));

    $queryParams = [
        'page' => '1',
        'api_key' => 'secret-key-123',
        'token' => 'bearer-token-456',
        'password' => 'admin123',
        'email' => 'user@example.com',
        'normal_param' => 'visible',
    ];

    $sanitized = $sanitizer->sanitizeQueryParams($queryParams);

    expect($sanitized['page'])->toBe('1');
    expect($sanitized['api_key'])->toBe('[REDACTED]');
    expect($sanitized['token'])->toBe('[REDACTED]');
    expect($sanitized['password'])->toBe('[REDACTED]');
    expect($sanitized['email'])->toMatch('/\*+@\*+\./'); // Should be masked
    expect($sanitized['normal_param'])->toBe('visible');
});

it('handles empty URL parameters', function () {
    $request = Request::create('/api/users', 'GET');

    $capture = new RequestCapture(config('apilogger'));
    $data = $capture->capture($request);

    expect($data['query_params'])->toBe([]);
});

it('handles complex nested URL parameters', function () {
    $request = Request::create('/api/search?filters[category]=electronics&filters[price_min]=100&filters[price_max]=500', 'GET');

    $capture = new RequestCapture(config('apilogger'));
    $data = $capture->capture($request);

    expect($data['query_params'])->toBe([
        'filters' => [
            'category' => 'electronics',
            'price_min' => '100',
            'price_max' => '500',
        ],
    ]);
});

it('sanitizes nested sensitive parameters', function () {
    $sanitizer = new DataSanitizer(config('apilogger'));

    $queryParams = [
        'user' => [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'api_key' => 'key-456',
        ],
        'page' => '1',
    ];

    $sanitized = $sanitizer->sanitizeQueryParams($queryParams);

    expect($sanitized['user']['name'])->toBe('John Doe');
    expect($sanitized['user']['email'])->toMatch('/\*+@\*+\./');
    expect($sanitized['user']['password'])->toBe('[REDACTED]');
    expect($sanitized['user']['api_key'])->toBe('[REDACTED]');
    expect($sanitized['page'])->toBe('1');
});

it('preserves URL parameters in JsonLineStorage', function () {
    Config::set('apilogger.storage.driver', 'jsonline');

    $logEntry = new LogEntry(
        requestId: 'json-test-123',
        method: 'GET',
        endpoint: '/api/products',
        requestHeaders: [],
        requestBody: null,
        responseCode: 200,
        responseHeaders: [],
        responseBody: ['products' => []],
        responseTimeMs: 25.0,
        requestParameters: ['category' => 'electronics', 'sort' => 'price'],
    );

    $storageManager = app(StorageManager::class);
    $stored = $storageManager->driver()->store($logEntry);

    expect($stored)->toBeTrue();

    // Verify the JSON includes request_parameters
    $jsonData = json_decode($logEntry->toJson(), true);
    expect($jsonData['request_parameters'])->toBe([
        'category' => 'electronics',
        'sort' => 'price',
    ]);
});

it('handles URL parameters in POST requests', function () {
    $request = Request::create('/api/users?include=profile,posts', 'POST', [], [], [], [], json_encode(['name' => 'John']));
    $request->headers->set('Content-Type', 'application/json');

    $capture = new RequestCapture(config('apilogger'));
    $data = $capture->capture($request);

    expect($data['query_params'])->toBe([
        'include' => 'profile,posts',
    ]);
    expect($data['body'])->toBe(['name' => 'John']);
});

it('retrieves logs by URL parameter values', function () {
    // Create multiple logs with different parameters
    $log1 = ApiLog::create([
        'method' => 'GET',
        'endpoint' => '/api/users',
        'request_headers' => [],
        'request_body' => null,
        'request_parameters' => ['page' => 1, 'limit' => 10],
        'response_code' => 200,
        'response_headers' => [],
        'response_body' => [],
        'response_time_ms' => 50.0,
        'direction' => 'inbound',
        'correlation_identifier' => 'test-1',
    ]);

    $log2 = ApiLog::create([
        'method' => 'GET',
        'endpoint' => '/api/users',
        'request_headers' => [],
        'request_body' => null,
        'request_parameters' => ['page' => 2, 'limit' => 10],
        'response_code' => 200,
        'response_headers' => [],
        'response_body' => [],
        'response_time_ms' => 45.0,
        'direction' => 'inbound',
        'correlation_identifier' => 'test-2',
    ]);

    // Query logs with specific parameter values using JSON path
    $logs = ApiLog::whereJsonContains('request_parameters->page', 1)->get();

    expect($logs)->toHaveCount(1);
    expect($logs->first()->correlation_identifier)->toBe('test-1');
});