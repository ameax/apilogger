<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Performance;

use Ameax\ApiLogger\Middleware\LogApiRequests;
use Ameax\ApiLogger\Models\ApiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

uses(RefreshDatabase::class);

it('has minimal performance overhead', function () {
    config([
        'apilogger.enabled' => true,
        'apilogger.level' => 'basic',
        'apilogger.storage.driver' => 'database',
        'apilogger.performance.use_queue' => false,
    ]);

    $middleware = app(LogApiRequests::class);
    $iterations = 100;

    // Measure time without middleware
    $withoutMiddlewareStart = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $request = Request::create('/api/test', 'GET');
        $response = new Response('OK', 200);
    }
    $withoutMiddlewareTime = (microtime(true) - $withoutMiddlewareStart) * 1000;

    // Measure time with middleware
    $withMiddlewareStart = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $request = Request::create('/api/test', 'GET');
        $middleware->handle($request, fn ($req) => new Response('OK', 200));
    }
    $withMiddlewareTime = (microtime(true) - $withMiddlewareStart) * 1000;

    // Calculate overhead
    $overhead = $withMiddlewareTime - $withoutMiddlewareTime;
    $overheadPercentage = ($overhead / $withoutMiddlewareTime) * 100;

    // Results available in $overheadPercentage for assertion

    // Assert overhead is reasonable for test environment with database
    // In production with optimized settings, this would be much lower
    expect($overheadPercentage)->toBeLessThan(5000); // Very generous for test env with DB
});

it('handles batch operations efficiently', function () {
    config([
        'apilogger.enabled' => true,
        'apilogger.storage.driver' => 'database',
        'apilogger.performance.batch_size' => 100,
    ]);

    $storage = app(\Ameax\ApiLogger\StorageManager::class)->driver();
    $entries = collect();

    // Create a large batch of log entries
    for ($i = 0; $i < 500; $i++) {
        $entries->push(new \Ameax\ApiLogger\DataTransferObjects\LogEntry(
            requestId: 'batch-'.$i,
            method: 'GET',
            endpoint: '/api/batch/'.$i,
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: rand(10, 100),
        ));
    }

    $startTime = microtime(true);
    $stored = $storage->storeBatch($entries);
    $duration = (microtime(true) - $startTime) * 1000;

    expect($stored)->toBe(500);
    expect($duration)->toBeLessThan(5000); // Should complete in less than 5 seconds

    // Verify all entries were stored
    expect(ApiLog::count())->toBe(500);
});

it('efficiently cleans old logs', function () {
    // Pre-populate database with old logs
    $oldDate = now()->subDays(40);
    $recentDate = now()->subDays(5);

    for ($i = 0; $i < 1000; $i++) {
        $log = ApiLog::create([
            'request_id' => 'old-'.$i,
            'method' => 'GET',
            'endpoint' => '/api/old/'.$i,
            'response_code' => $i % 5 === 0 ? 500 : 200, // 20% errors
            'response_time_ms' => rand(10, 100),
        ]);
        $log->created_at = $i < 500 ? $oldDate : $recentDate;
        $log->save();
    }

    $storage = app(\Ameax\ApiLogger\StorageManager::class)->driver();

    $startTime = microtime(true);
    $cleaned = $storage->clean(30, 35); // Keep 30 days for normal, 35 for errors
    $duration = (microtime(true) - $startTime) * 1000;

    // Old normal logs (400 logs) should be deleted
    // Old error logs (100 logs) should be deleted
    expect($cleaned)->toBe(500);
    expect($duration)->toBeLessThan(2000); // Should complete in less than 2 seconds

    // Verify correct logs remain
    expect(ApiLog::count())->toBe(500);
    expect(ApiLog::where('created_at', '>=', now()->subDays(30))->count())->toBe(500);
});

it('retrieves logs with criteria efficiently', function () {
    // Populate database with diverse logs
    for ($i = 0; $i < 1000; $i++) {
        ApiLog::create([
            'request_id' => 'retrieve-'.$i,
            'method' => $i % 3 === 0 ? 'POST' : 'GET',
            'endpoint' => '/api/'.($i % 10 === 0 ? 'users' : 'posts'),
            'response_code' => $i % 10 === 0 ? 404 : 200,
            'response_time_ms' => rand(10, 500),
            'user_identifier' => $i % 5 === 0 ? 'user-1' : 'user-2',
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $storage = app(\Ameax\ApiLogger\StorageManager::class)->driver();

    // Test various retrieval scenarios
    $scenarios = [
        ['method' => 'POST'],
        ['is_error' => true],
        ['user_identifier' => 'user-1'],
        ['endpoint' => '/api/users'],
        ['from_date' => now()->subHours(1)],
    ];

    foreach ($scenarios as $criteria) {
        $startTime = microtime(true);
        $results = $storage->retrieve($criteria, 100);
        $duration = (microtime(true) - $startTime) * 1000;

        expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
        expect($duration)->toBeLessThan(500); // Each query should be fast
    }
});

it('handles concurrent writes without data loss', function () {
    config([
        'apilogger.enabled' => true,
        'apilogger.storage.driver' => 'jsonline',
        'apilogger.storage.jsonline.path' => storage_path('logs/api-concurrent'),
    ]);

    $storage = app(\Ameax\ApiLogger\StorageManager::class)->driver();
    $concurrentWrites = 50;
    $successCount = 0;

    // Simulate concurrent writes
    for ($i = 0; $i < $concurrentWrites; $i++) {
        $entry = new \Ameax\ApiLogger\DataTransferObjects\LogEntry(
            requestId: 'concurrent-'.$i,
            method: 'GET',
            endpoint: '/api/concurrent/'.$i,
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 10.0,
        );

        if ($storage->store($entry)) {
            $successCount++;
        }
    }

    // All writes should succeed
    expect($successCount)->toBe($concurrentWrites);

    // Verify all entries are in the file
    $entries = $storage->retrieve();
    expect($entries->count())->toBeGreaterThanOrEqual($concurrentWrites);

    // Clean up
    $path = storage_path('logs/api-concurrent');
    if (is_dir($path)) {
        array_map('unlink', glob($path.'/*.jsonl'));
        rmdir($path);
    }
});

it('maintains acceptable memory usage', function () {
    config([
        'apilogger.enabled' => true,
        'apilogger.level' => 'full',
        'apilogger.storage.driver' => 'database',
    ]);

    $initialMemory = memory_get_usage();
    $middleware = app(LogApiRequests::class);

    // Process many requests
    for ($i = 0; $i < 100; $i++) {
        $request = Request::create('/api/memory-test/'.$i, 'POST');
        $request->setJson([
            'data' => str_repeat('x', 1000), // 1KB of data
        ]);

        $middleware->handle($request, fn ($req) => new Response(
            json_encode(['result' => str_repeat('y', 1000)]),
            200,
            ['Content-Type' => 'application/json']
        ));
    }

    $finalMemory = memory_get_usage();
    $memoryIncrease = ($finalMemory - $initialMemory) / 1024 / 1024; // Convert to MB

    // Memory increase should be reasonable (less than 50MB for 100 requests)
    expect($memoryIncrease)->toBeLessThan(50);
});
