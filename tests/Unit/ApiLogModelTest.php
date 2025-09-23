<?php

declare(strict_types=1);

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Models\ApiLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run the migration manually since it's a stub file
    $migrationFile = __DIR__.'/../../database/migrations/create_api_logs_table.php.stub';
    $migration = require $migrationFile;
    $migration->up();
});

it('creates an ApiLog model with correct attributes', function () {

    $log = ApiLog::create([
        'request_id' => 'test-123',
        'method' => 'GET',
        'endpoint' => '/api/test',
        'request_headers' => ['Accept' => 'application/json'],
        'request_body' => ['param' => 'value'],
        'response_code' => 200,
        'response_headers' => ['Content-Type' => 'application/json'],
        'response_body' => ['success' => true],
        'response_time_ms' => 125.5,
        'user_identifier' => 'user-1',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Test Agent',
        'metadata' => ['extra' => 'data'],
    ]);

    expect($log)->toBeInstanceOf(ApiLog::class)
        ->and($log->request_id)->toBe('test-123')
        ->and($log->method)->toBe('GET')
        ->and($log->endpoint)->toBe('/api/test')
        ->and($log->response_code)->toBe(200)
        ->and($log->response_time_ms)->toBe(125.5)
        ->and($log->user_identifier)->toBe('user-1')
        ->and($log->ip_address)->toBe('192.168.1.1');
});

it('converts ApiLog to LogEntry', function () {

    $log = ApiLog::create([
        'request_id' => 'test-456',
        'method' => 'POST',
        'endpoint' => '/api/users',
        'request_headers' => [],
        'request_body' => ['name' => 'John'],
        'response_code' => 201,
        'response_headers' => [],
        'response_body' => ['id' => 1],
        'response_time_ms' => 200.0,
    ]);

    $entry = $log->toLogEntry();

    expect($entry)->toBeInstanceOf(LogEntry::class)
        ->and($entry->getRequestId())->toBe('test-456')
        ->and($entry->getMethod())->toBe('POST')
        ->and($entry->getResponseCode())->toBe(201);
});

it('creates ApiLog from LogEntry', function () {
    $entry = new LogEntry(
        requestId: 'test-789',
        method: 'PUT',
        endpoint: '/api/users/1',
        requestHeaders: [],
        requestBody: ['name' => 'Jane'],
        responseCode: 200,
        responseHeaders: [],
        responseBody: ['updated' => true],
        responseTimeMs: 150.0,
    );

    $log = ApiLog::fromLogEntry($entry);

    expect($log->request_id)->toBe('test-789')
        ->and($log->method)->toBe('PUT')
        ->and($log->endpoint)->toBe('/api/users/1')
        ->and($log->response_code)->toBe(200);
});

it('uses error scopes correctly', function () {

    // Create various logs
    ApiLog::create(['request_id' => '1', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    ApiLog::create(['request_id' => '2', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 404, 'response_time_ms' => 10]);
    ApiLog::create(['request_id' => '3', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 500, 'response_time_ms' => 10]);

    expect(ApiLog::successful()->count())->toBe(1)
        ->and(ApiLog::errors()->count())->toBe(2)
        ->and(ApiLog::clientErrors()->count())->toBe(1)
        ->and(ApiLog::serverErrors()->count())->toBe(1);
});

it('uses user scope correctly', function () {

    ApiLog::create(['request_id' => '1', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'user_identifier' => 'user-1']);
    ApiLog::create(['request_id' => '2', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'user_identifier' => 'user-2']);
    ApiLog::create(['request_id' => '3', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'user_identifier' => 'user-1']);

    expect(ApiLog::forUser('user-1')->count())->toBe(2)
        ->and(ApiLog::forUser('user-2')->count())->toBe(1);
});

it('uses endpoint scope correctly', function () {

    ApiLog::create(['request_id' => '1', 'method' => 'GET', 'endpoint' => '/api/users', 'response_code' => 200, 'response_time_ms' => 10]);
    ApiLog::create(['request_id' => '2', 'method' => 'POST', 'endpoint' => '/api/users', 'response_code' => 201, 'response_time_ms' => 10]);
    ApiLog::create(['request_id' => '3', 'method' => 'GET', 'endpoint' => '/api/posts', 'response_code' => 200, 'response_time_ms' => 10]);

    expect(ApiLog::forEndpoint('/api/users')->count())->toBe(2)
        ->and(ApiLog::forEndpoint('/api/users', 'GET')->count())->toBe(1)
        ->and(ApiLog::forEndpoint('/api/posts')->count())->toBe(1);
});

it('uses slow requests scope correctly', function () {

    ApiLog::create(['request_id' => '1', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 500]);
    ApiLog::create(['request_id' => '2', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 1500]);
    ApiLog::create(['request_id' => '3', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 2000]);

    expect(ApiLog::slow()->count())->toBe(2)
        ->and(ApiLog::slow(1500)->count())->toBe(1);
});

it('uses date range scope correctly', function () {

    $yesterday = Carbon::yesterday()->midDay();
    $today = Carbon::today()->midDay();
    $tomorrow = Carbon::tomorrow()->midDay();

    $log1 = ApiLog::create(['request_id' => '1', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log1->created_at = $yesterday;
    $log1->save();

    $log2 = ApiLog::create(['request_id' => '2', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log2->created_at = $today;
    $log2->save();

    $log3 = ApiLog::create(['request_id' => '3', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log3->created_at = $tomorrow;
    $log3->save();

    expect(ApiLog::betweenDates(Carbon::yesterday()->startOfDay(), Carbon::today()->endOfDay())->count())->toBe(2)
        ->and(ApiLog::betweenDates(Carbon::today()->startOfDay(), Carbon::tomorrow()->endOfDay())->count())->toBe(2);
});

it('uses older than scope correctly', function () {

    $log1 = ApiLog::create(['request_id' => '1', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log1->created_at = Carbon::now()->subDays(10);
    $log1->save();

    $log2 = ApiLog::create(['request_id' => '2', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log2->created_at = Carbon::now()->subDays(5);
    $log2->save();

    $log3 = ApiLog::create(['request_id' => '3', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log3->created_at = Carbon::now();
    $log3->save();

    expect(ApiLog::olderThan(7)->count())->toBe(1)
        ->and(ApiLog::olderThan(3)->count())->toBe(2);
});

it('identifies response types correctly', function () {

    $successLog = ApiLog::create(['request_id' => '1', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $errorLog = ApiLog::create(['request_id' => '2', 'method' => 'GET', 'endpoint' => '/test', 'response_code' => 500, 'response_time_ms' => 10]);

    expect($successLog->isSuccess())->toBeTrue()
        ->and($successLog->isError())->toBeFalse()
        ->and($errorLog->isSuccess())->toBeFalse()
        ->and($errorLog->isError())->toBeTrue();
});
