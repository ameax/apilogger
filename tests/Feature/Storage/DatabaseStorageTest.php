<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Feature\Storage;

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Models\ApiLog;
use Ameax\ApiLogger\Storage\DatabaseStorage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->storage = new DatabaseStorage(
        app('db'),
        ['table' => 'api_logs', 'batch_size' => 10]
    );
});

describe('DatabaseStorage', function () {
    test('can store a single log entry', function () {
        $entry = new LogEntry(
            requestId: 'test-123',
            method: 'GET',
            endpoint: '/api/users',
            requestHeaders: ['Accept' => 'application/json'],
            requestBody: null,
            responseCode: 200,
            responseHeaders: ['Content-Type' => 'application/json'],
            responseBody: ['users' => []],
            responseTimeMs: 45.5,
            userIdentifier: 'user-1',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        );

        $result = $this->storage->store($entry);

        expect($result)->toBeTrue();

        $log = ApiLog::where('request_id', 'test-123')->first();
        expect($log)->not->toBeNull();
        expect($log->method)->toBe('GET');
        expect($log->endpoint)->toBe('/api/users');
        expect($log->response_code)->toBe(200);
        expect($log->response_time_ms)->toBe(45.5);
        expect($log->user_identifier)->toBe('user-1');
    });

    test('can store multiple entries in batch', function () {
        $entries = new Collection([
            new LogEntry(
                requestId: 'batch-1',
                method: 'GET',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 200,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 10.0,
            ),
            new LogEntry(
                requestId: 'batch-2',
                method: 'POST',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: ['name' => 'John'],
                responseCode: 201,
                responseHeaders: [],
                responseBody: ['id' => 1],
                responseTimeMs: 20.0,
            ),
            new LogEntry(
                requestId: 'batch-3',
                method: 'DELETE',
                endpoint: '/api/users/1',
                requestHeaders: [],
                requestBody: null,
                responseCode: 204,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 15.0,
            ),
        ]);

        $stored = $this->storage->storeBatch($entries);

        expect($stored)->toBe(3);
        expect(ApiLog::count())->toBe(3);
        expect(ApiLog::where('request_id', 'batch-1')->exists())->toBeTrue();
        expect(ApiLog::where('request_id', 'batch-2')->exists())->toBeTrue();
        expect(ApiLog::where('request_id', 'batch-3')->exists())->toBeTrue();
    });

    test('handles large batches by chunking', function () {
        $entries = new Collection;
        for ($i = 1; $i <= 25; $i++) {
            $entries->push(new LogEntry(
                requestId: "large-{$i}",
                method: 'GET',
                endpoint: "/api/item/{$i}",
                requestHeaders: [],
                requestBody: null,
                responseCode: 200,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: rand(10, 100),
            ));
        }

        $stored = $this->storage->storeBatch($entries);

        expect($stored)->toBe(25);
        expect(ApiLog::count())->toBe(25);
    });

    test('can retrieve entries with criteria', function () {
        // Create test entries
        ApiLog::create([
            'request_id' => 'retrieve-1',
            'method' => 'GET',
            'endpoint' => '/api/users',
            'response_code' => 200,
            'response_time_ms' => 50.0,
            'user_identifier' => 'user-1',
            'created_at' => Carbon::now()->subHours(2),
        ]);

        ApiLog::create([
            'request_id' => 'retrieve-2',
            'method' => 'POST',
            'endpoint' => '/api/users',
            'response_code' => 201,
            'response_time_ms' => 100.0,
            'user_identifier' => 'user-2',
            'created_at' => Carbon::now()->subHour(),
        ]);

        ApiLog::create([
            'request_id' => 'retrieve-3',
            'method' => 'GET',
            'endpoint' => '/api/posts',
            'response_code' => 404,
            'response_time_ms' => 25.0,
            'user_identifier' => 'user-1',
            'created_at' => Carbon::now(),
        ]);

        // Test retrieval with method filter
        $results = $this->storage->retrieve(['method' => 'GET']);
        expect($results)->toHaveCount(2);

        // Test retrieval with user filter
        $results = $this->storage->retrieve(['user_identifier' => 'user-1']);
        expect($results)->toHaveCount(2);

        // Test retrieval with error filter
        $results = $this->storage->retrieve(['is_error' => true]);
        expect($results)->toHaveCount(1);
        expect($results->first()->getRequestId())->toBe('retrieve-3');

        // Test retrieval with response time filter
        $results = $this->storage->retrieve(['min_response_time' => 60]);
        expect($results)->toHaveCount(1);
        expect($results->first()->getRequestId())->toBe('retrieve-2');

        // Test limit and offset
        $results = $this->storage->retrieve([], 2, 1);
        expect($results)->toHaveCount(2);
    });

    test('can find entry by request ID', function () {
        ApiLog::create([
            'request_id' => 'find-me',
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 30.0,
        ]);

        $entry = $this->storage->findByRequestId('find-me');

        expect($entry)->not->toBeNull();
        expect($entry->getRequestId())->toBe('find-me');
        expect($entry->getMethod())->toBe('GET');
        expect($entry->getEndpoint())->toBe('/api/test');

        // Test non-existent ID
        $notFound = $this->storage->findByRequestId('does-not-exist');
        expect($notFound)->toBeNull();
    });

    test('can delete entries with criteria', function () {
        // Create test entries
        ApiLog::create([
            'request_id' => 'delete-1',
            'method' => 'GET',
            'endpoint' => '/api/users',
            'response_code' => 200,
            'response_time_ms' => 50.0,
            'created_at' => Carbon::now()->subDays(10),
        ]);

        ApiLog::create([
            'request_id' => 'delete-2',
            'method' => 'POST',
            'endpoint' => '/api/users',
            'response_code' => 201,
            'response_time_ms' => 100.0,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        ApiLog::create([
            'request_id' => 'delete-3',
            'method' => 'GET',
            'endpoint' => '/api/posts',
            'response_code' => 200,
            'response_time_ms' => 25.0,
            'created_at' => Carbon::now(),
        ]);

        // Delete by method
        $deleted = $this->storage->delete(['method' => 'POST']);
        expect($deleted)->toBe(1);
        expect(ApiLog::count())->toBe(2);

        // Delete older than days
        $deleted = $this->storage->delete(['older_than_days' => 7]);
        expect($deleted)->toBe(1);
        expect(ApiLog::count())->toBe(1);
        expect(ApiLog::where('request_id', 'delete-3')->exists())->toBeTrue();
    });

    test('can delete by request ID', function () {
        ApiLog::create([
            'request_id' => 'to-delete',
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 30.0,
        ]);

        $result = $this->storage->deleteByRequestId('to-delete');

        expect($result)->toBeTrue();
        expect(ApiLog::where('request_id', 'to-delete')->exists())->toBeFalse();

        // Test deleting non-existent
        $result = $this->storage->deleteByRequestId('does-not-exist');
        expect($result)->toBeFalse();
    });

    test('can clean old entries with different retention for errors', function () {
        // Create old normal entries
        ApiLog::create([
            'request_id' => 'old-normal-1',
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 30.0,
            'created_at' => Carbon::now()->subDays(8),
        ]);

        // Create old error entries
        ApiLog::create([
            'request_id' => 'old-error-1',
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 500,
            'response_time_ms' => 30.0,
            'created_at' => Carbon::now()->subDays(12),
        ]);

        // Create recent entries
        ApiLog::create([
            'request_id' => 'recent-normal',
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 30.0,
            'created_at' => Carbon::now()->subDays(2),
        ]);

        ApiLog::create([
            'request_id' => 'recent-error',
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 404,
            'response_time_ms' => 30.0,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        $cleaned = $this->storage->clean(7, 10);

        expect($cleaned)->toBe(2); // old-normal-1 and old-error-1
        expect(ApiLog::count())->toBe(2);
        expect(ApiLog::where('request_id', 'recent-normal')->exists())->toBeTrue();
        expect(ApiLog::where('request_id', 'recent-error')->exists())->toBeTrue();
    });

    test('can count entries with criteria', function () {
        // Create test entries
        ApiLog::create([
            'request_id' => 'count-1',
            'method' => 'GET',
            'endpoint' => '/api/users',
            'response_code' => 200,
            'response_time_ms' => 50.0,
        ]);

        ApiLog::create([
            'request_id' => 'count-2',
            'method' => 'GET',
            'endpoint' => '/api/users',
            'response_code' => 404,
            'response_time_ms' => 25.0,
        ]);

        ApiLog::create([
            'request_id' => 'count-3',
            'method' => 'POST',
            'endpoint' => '/api/users',
            'response_code' => 201,
            'response_time_ms' => 100.0,
        ]);

        expect($this->storage->count())->toBe(3);
        expect($this->storage->count(['method' => 'GET']))->toBe(2);
        expect($this->storage->count(['is_error' => true]))->toBe(1);
        expect($this->storage->count(['endpoint' => '/api/users']))->toBe(3);
    });

    test('can check if storage is available', function () {
        expect($this->storage->isAvailable())->toBeTrue();
    });

    test('can get statistics', function () {
        // Create test data
        ApiLog::create([
            'request_id' => 'stats-1',
            'method' => 'GET',
            'endpoint' => '/api/users',
            'response_code' => 200,
            'response_time_ms' => 50.0,
        ]);

        ApiLog::create([
            'request_id' => 'stats-2',
            'method' => 'POST',
            'endpoint' => '/api/users',
            'response_code' => 500,
            'response_time_ms' => 150.0,
        ]);

        ApiLog::create([
            'request_id' => 'stats-3',
            'method' => 'GET',
            'endpoint' => '/api/posts',
            'response_code' => 404,
            'response_time_ms' => 25.0,
        ]);

        $stats = $this->storage->getStatistics();

        expect($stats)->toHaveKeys([
            'total_entries',
            'total_errors',
            'total_success',
            'avg_response_time',
            'max_response_time',
            'min_response_time',
            'storage_type',
            'table_name',
            'connection',
            'status_groups',
            'top_endpoints',
        ]);

        expect($stats['total_entries'])->toBe(3);
        expect($stats['total_errors'])->toBe(2);
        expect($stats['total_success'])->toBe(1);
        expect($stats['max_response_time'])->toBeNumeric();
        expect((float) $stats['max_response_time'])->toBe(150.0);
        expect($stats['min_response_time'])->toBeNumeric();
        expect((float) $stats['min_response_time'])->toBe(25.0);
        expect($stats['storage_type'])->toBe('database');
        expect($stats['status_groups']['2xx'])->toBe(1);
        expect($stats['status_groups']['4xx'])->toBe(1);
        expect($stats['status_groups']['5xx'])->toBe(1);
    });

    test('handles database errors gracefully', function () {
        // Create a storage instance with invalid connection
        $storage = new DatabaseStorage(
            app('db'),
            ['connection' => 'invalid_connection', 'table' => 'api_logs']
        );

        $entry = new LogEntry(
            requestId: 'error-test',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 10.0,
        );

        // Should handle error and return false
        $result = $storage->store($entry);
        expect($result)->toBeFalse();
    });
});
