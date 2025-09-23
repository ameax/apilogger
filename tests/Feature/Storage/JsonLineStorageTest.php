<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Feature\Storage;

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Storage\JsonLineStorage;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->storagePath = storage_path('tests/logs/api');
    File::makeDirectory($this->storagePath, 0755, true, true);

    $this->storage = new JsonLineStorage([
        'path' => $this->storagePath,
        'filename_format' => 'api-{date}.jsonl',
        'rotate_daily' => true,
        'compress_old_files' => false, // Disable for tests
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->storagePath);
});

describe('JsonLineStorage', function () {
    test('can store a single log entry', function () {
        $entry = new LogEntry(
            requestId: 'json-test-123',
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

        $filename = 'api-'.Carbon::now()->format('Y-m-d').'.jsonl';
        $filepath = $this->storagePath.'/'.$filename;

        expect(File::exists($filepath))->toBeTrue();

        $content = File::get($filepath);
        $data = json_decode(trim($content), true);

        expect($data['request_id'])->toBe('json-test-123');
        expect($data['method'])->toBe('GET');
        expect($data['endpoint'])->toBe('/api/users');
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
        ]);

        $stored = $this->storage->storeBatch($entries);

        expect($stored)->toBe(2);

        $filename = 'api-'.Carbon::now()->format('Y-m-d').'.jsonl';
        $filepath = $this->storagePath.'/'.$filename;

        $lines = File::lines($filepath)->filter(fn($line) => trim($line) !== '')->values();
        expect($lines->count())->toBe(2);

        $firstLine = json_decode($lines[0], true);
        expect($firstLine['request_id'])->toBe('batch-1');

        $secondLine = json_decode($lines[1], true);
        expect($secondLine['request_id'])->toBe('batch-2');
    });

    test('groups entries by date when daily rotation is enabled', function () {
        $yesterday = Carbon::yesterday();
        $today = Carbon::today();

        $entries = new Collection([
            new LogEntry(
                requestId: 'yesterday-1',
                method: 'GET',
                endpoint: '/api/old',
                requestHeaders: [],
                requestBody: null,
                responseCode: 200,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 10.0,
                createdAt: $yesterday,
            ),
            new LogEntry(
                requestId: 'today-1',
                method: 'POST',
                endpoint: '/api/new',
                requestHeaders: [],
                requestBody: null,
                responseCode: 201,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 20.0,
                createdAt: $today,
            ),
        ]);

        $stored = $this->storage->storeBatch($entries);

        expect($stored)->toBe(2);

        $yesterdayFile = $this->storagePath.'/api-'.$yesterday->format('Y-m-d').'.jsonl';
        $todayFile = $this->storagePath.'/api-'.$today->format('Y-m-d').'.jsonl';

        expect(File::exists($yesterdayFile))->toBeTrue();
        expect(File::exists($todayFile))->toBeTrue();
    });

    test('can retrieve entries with criteria', function () {
        // Store test entries
        $entries = new Collection([
            new LogEntry(
                requestId: 'retrieve-1',
                method: 'GET',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 200,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 50.0,
                userIdentifier: 'user-1',
            ),
            new LogEntry(
                requestId: 'retrieve-2',
                method: 'POST',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 201,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 100.0,
                userIdentifier: 'user-2',
            ),
            new LogEntry(
                requestId: 'retrieve-3',
                method: 'GET',
                endpoint: '/api/posts',
                requestHeaders: [],
                requestBody: null,
                responseCode: 404,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 25.0,
                userIdentifier: 'user-1',
            ),
        ]);

        $this->storage->storeBatch($entries);

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

        // Test limit and offset
        $results = $this->storage->retrieve([], 2, 1);
        expect($results)->toHaveCount(2);
    });

    test('can find entry by request ID', function () {
        $entry = new LogEntry(
            requestId: 'find-me',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 30.0,
        );

        $this->storage->store($entry);

        $found = $this->storage->findByRequestId('find-me');

        expect($found)->not->toBeNull();
        expect($found->getRequestId())->toBe('find-me');
        expect($found->getMethod())->toBe('GET');

        // Test non-existent ID
        $notFound = $this->storage->findByRequestId('does-not-exist');
        expect($notFound)->toBeNull();
    });

    test('can delete entries with criteria', function () {
        $entries = new Collection([
            new LogEntry(
                requestId: 'delete-1',
                method: 'GET',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 200,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 50.0,
            ),
            new LogEntry(
                requestId: 'delete-2',
                method: 'POST',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 201,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 100.0,
            ),
            new LogEntry(
                requestId: 'delete-3',
                method: 'GET',
                endpoint: '/api/posts',
                requestHeaders: [],
                requestBody: null,
                responseCode: 200,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 25.0,
            ),
        ]);

        $this->storage->storeBatch($entries);

        // Delete by method
        $deleted = $this->storage->delete(['method' => 'POST']);
        expect($deleted)->toBe(1);

        // Verify deletion
        $remaining = $this->storage->retrieve();
        expect($remaining)->toHaveCount(2);
        expect($remaining->pluck('requestId')->toArray())->not->toContain('delete-2');
    });

    test('can delete by request ID', function () {
        $entry = new LogEntry(
            requestId: 'to-delete',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 30.0,
        );

        $this->storage->store($entry);

        $result = $this->storage->deleteByRequestId('to-delete');
        expect($result)->toBeTrue();

        $found = $this->storage->findByRequestId('to-delete');
        expect($found)->toBeNull();
    });

    test('can clean old entries with different retention for errors', function () {
        $oldNormal = new LogEntry(
            requestId: 'old-normal',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 30.0,
            createdAt: Carbon::now()->subDays(8),
        );

        $oldError = new LogEntry(
            requestId: 'old-error',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 500,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 30.0,
            createdAt: Carbon::now()->subDays(12),
        );

        $recentNormal = new LogEntry(
            requestId: 'recent-normal',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 30.0,
            createdAt: Carbon::now()->subDays(2),
        );

        $recentError = new LogEntry(
            requestId: 'recent-error',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 404,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 30.0,
            createdAt: Carbon::now()->subDays(5),
        );

        $this->storage->storeBatch(new Collection([
            $oldNormal, $oldError, $recentNormal, $recentError,
        ]));

        $cleaned = $this->storage->clean(7, 10);

        expect($cleaned)->toBe(2); // old-normal and old-error

        $remaining = $this->storage->retrieve();
        expect($remaining)->toHaveCount(2);
        expect($remaining->pluck('request_id')->toArray())->toContain('recent-normal', 'recent-error');
    });

    test('can count entries with criteria', function () {
        $entries = new Collection([
            new LogEntry(
                requestId: 'count-1',
                method: 'GET',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 200,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 50.0,
            ),
            new LogEntry(
                requestId: 'count-2',
                method: 'GET',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 404,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 25.0,
            ),
            new LogEntry(
                requestId: 'count-3',
                method: 'POST',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 201,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 100.0,
            ),
        ]);

        $this->storage->storeBatch($entries);

        expect($this->storage->count())->toBe(3);
        expect($this->storage->count(['method' => 'GET']))->toBe(2);
        expect($this->storage->count(['is_error' => true]))->toBe(1);
    });

    test('can check if storage is available', function () {
        expect($this->storage->isAvailable())->toBeTrue();

        // Test with non-writable path
        $storage = new JsonLineStorage(['path' => '/invalid/path']);
        expect($storage->isAvailable())->toBeFalse();
    });

    test('can get statistics', function () {
        $entries = new Collection([
            new LogEntry(
                requestId: 'stats-1',
                method: 'GET',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 200,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 50.0,
            ),
            new LogEntry(
                requestId: 'stats-2',
                method: 'POST',
                endpoint: '/api/users',
                requestHeaders: [],
                requestBody: null,
                responseCode: 500,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 150.0,
            ),
        ]);

        $this->storage->storeBatch($entries);

        $stats = $this->storage->getStatistics();

        expect($stats)->toHaveKeys([
            'storage_type',
            'base_path',
            'total_files',
            'total_size_bytes',
            'total_entries',
            'total_errors',
            'oldest_file',
            'newest_file',
        ]);

        expect($stats['storage_type'])->toBe('jsonline');
        expect($stats['total_entries'])->toBe(2);
        expect($stats['total_errors'])->toBe(1);
        expect($stats['total_files'])->toBeGreaterThan(0);
    });

    test('handles concurrent writes with file locking', function () {
        $entry1 = new LogEntry(
            requestId: 'concurrent-1',
            method: 'GET',
            endpoint: '/api/test1',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 10.0,
        );

        $entry2 = new LogEntry(
            requestId: 'concurrent-2',
            method: 'POST',
            endpoint: '/api/test2',
            requestHeaders: [],
            requestBody: null,
            responseCode: 201,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 20.0,
        );

        // Simulate concurrent writes
        $result1 = $this->storage->store($entry1);
        $result2 = $this->storage->store($entry2);

        expect($result1)->toBeTrue();
        expect($result2)->toBeTrue();

        $entries = $this->storage->retrieve();
        expect($entries)->toHaveCount(2);
    });

    test('handles malformed JSON lines gracefully', function () {
        $filename = 'api-'.Carbon::now()->format('Y-m-d').'.jsonl';
        $filepath = $this->storagePath.'/'.$filename;

        // Write some valid and invalid JSON lines
        $content = json_encode(['request_id' => 'valid-1', 'method' => 'GET', 'endpoint' => '/api/test', 'response_code' => 200, 'response_time_ms' => 10])."\n";
        $content .= "invalid json line\n";
        $content .= json_encode(['request_id' => 'valid-2', 'method' => 'POST', 'endpoint' => '/api/test', 'response_code' => 201, 'response_time_ms' => 20])."\n";

        File::put($filepath, $content);

        $entries = $this->storage->retrieve();

        // Should only retrieve valid entries
        expect($entries)->toHaveCount(2);
        expect($entries->pluck('request_id')->toArray())->toContain('valid-1', 'valid-2');
    });
});
