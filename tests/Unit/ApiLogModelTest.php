<?php

declare(strict_types=1);

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Models\ApiLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an ApiLog model with correct attributes', function () {

    $log = ApiLog::create([
        'correlation_identifier' => 'test-123',
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
        ->and($log->id)->toBeGreaterThan(0)
        ->and($log->correlation_identifier)->toBe('test-123')
        ->and($log->method)->toBe('GET')
        ->and($log->endpoint)->toBe('/api/test')
        ->and($log->response_code)->toBe(200)
        ->and($log->response_time_ms)->toBe(125.5)
        ->and($log->user_identifier)->toBe('user-1')
        ->and($log->ip_address)->toBe('192.168.1.1');
});

it('converts ApiLog to LogEntry', function () {

    $log = ApiLog::create([
        'correlation_identifier' => 'test-456',
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
        ->and($entry->getRequestId())->toBe((string) $log->id)
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

    expect($log->correlation_identifier)->toBe('test-789')
        ->and($log->method)->toBe('PUT')
        ->and($log->endpoint)->toBe('/api/users/1')
        ->and($log->response_code)->toBe(200);
});

it('uses error scopes correctly', function () {

    // Create various logs
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 404, 'response_time_ms' => 10]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 500, 'response_time_ms' => 10]);

    expect(ApiLog::successful()->count())->toBe(1)
        ->and(ApiLog::errors()->count())->toBe(2)
        ->and(ApiLog::clientErrors()->count())->toBe(1)
        ->and(ApiLog::serverErrors()->count())->toBe(1);
});

it('uses user scope correctly', function () {

    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'user_identifier' => 'user-1']);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'user_identifier' => 'user-2']);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'user_identifier' => 'user-1']);

    expect(ApiLog::forUser('user-1')->count())->toBe(2)
        ->and(ApiLog::forUser('user-2')->count())->toBe(1);
});

it('uses endpoint scope correctly', function () {

    ApiLog::create(['method' => 'GET', 'endpoint' => '/api/users', 'response_code' => 200, 'response_time_ms' => 10]);
    ApiLog::create(['method' => 'POST', 'endpoint' => '/api/users', 'response_code' => 201, 'response_time_ms' => 10]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/api/posts', 'response_code' => 200, 'response_time_ms' => 10]);

    expect(ApiLog::forEndpoint('/api/users')->count())->toBe(2)
        ->and(ApiLog::forEndpoint('/api/users', 'GET')->count())->toBe(1)
        ->and(ApiLog::forEndpoint('/api/posts')->count())->toBe(1);
});

it('uses slow requests scope correctly', function () {

    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 500]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 1500]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 2000]);

    expect(ApiLog::slow()->count())->toBe(2)
        ->and(ApiLog::slow(1500)->count())->toBe(1);
});

it('uses date range scope correctly', function () {

    $yesterday = Carbon::yesterday()->midDay();
    $today = Carbon::today()->midDay();
    $tomorrow = Carbon::tomorrow()->midDay();

    $log1 = ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log1->created_at = $yesterday;
    $log1->save();

    $log2 = ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log2->created_at = $today;
    $log2->save();

    $log3 = ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log3->created_at = $tomorrow;
    $log3->save();

    expect(ApiLog::betweenDates(Carbon::yesterday()->startOfDay(), Carbon::today()->endOfDay())->count())->toBe(2)
        ->and(ApiLog::betweenDates(Carbon::today()->startOfDay(), Carbon::tomorrow()->endOfDay())->count())->toBe(2);
});

it('uses older than scope correctly', function () {

    $log1 = ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log1->created_at = Carbon::now()->subDays(10);
    $log1->save();

    $log2 = ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log2->created_at = Carbon::now()->subDays(5);
    $log2->save();

    $log3 = ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $log3->created_at = Carbon::now();
    $log3->save();

    expect(ApiLog::olderThan(7)->count())->toBe(1)
        ->and(ApiLog::olderThan(3)->count())->toBe(2);
});

it('identifies response types correctly', function () {

    $successLog = ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10]);
    $errorLog = ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 500, 'response_time_ms' => 10]);

    expect($successLog->isSuccess())->toBeTrue()
        ->and($successLog->isError())->toBeFalse()
        ->and($errorLog->isSuccess())->toBeFalse()
        ->and($errorLog->isError())->toBeTrue();
});

it('uses marked scope correctly', function () {
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => true]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => false]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => true]);

    expect(ApiLog::marked()->count())->toBe(2);
});

it('uses withComments scope correctly', function () {
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'comment' => 'Important log']);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'comment' => null]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'comment' => 'Another comment']);

    expect(ApiLog::withComments()->count())->toBe(2);
});

it('uses preserved scope correctly', function () {
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => true, 'comment' => null]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => false, 'comment' => 'Has comment']);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => false, 'comment' => null]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => true, 'comment' => 'Both']);

    expect(ApiLog::preserved()->count())->toBe(3);
});

it('uses notPreserved scope correctly', function () {
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => true, 'comment' => null]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => false, 'comment' => 'Has comment']);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => false, 'comment' => null]);
    ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 10, 'is_marked' => true, 'comment' => 'Both']);

    expect(ApiLog::notPreserved()->count())->toBe(1);
});

it('stores and retrieves comment and is_marked fields correctly', function () {
    $log = ApiLog::create([
        'correlation_identifier' => 'test-comment',
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 500,
        'response_time_ms' => 125.5,
        'comment' => 'This request failed due to a database connection issue',
        'is_marked' => true,
    ]);

    expect($log->comment)->toBe('This request failed due to a database connection issue')
        ->and($log->is_marked)->toBeTrue();

    $retrieved = ApiLog::find($log->id);
    expect($retrieved->comment)->toBe('This request failed due to a database connection issue')
        ->and($retrieved->is_marked)->toBeTrue();
});
