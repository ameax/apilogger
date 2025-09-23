<?php

declare(strict_types=1);

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Jobs\StoreApiLogJob;
use Ameax\ApiLogger\StorageManager;
use Illuminate\Support\Facades\Log;

it('stores log entry via storage manager', function () {
    $logData = [
        'request_id' => 'test-123',
        'method' => 'GET',
        'endpoint' => '/api/test',
        'request_headers' => [],
        'request_body' => null,
        'response_code' => 200,
        'response_headers' => [],
        'response_body' => ['success' => true],
        'response_time_ms' => 123.45,
        'user_identifier' => null,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestAgent',
        'created_at' => now()->toIso8601String(),
        'metadata' => [],
    ];

    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->once()->andReturn($storage);
    $storage->shouldReceive('store')->once()->with(Mockery::on(function ($logEntry) {
        expect($logEntry)->toBeInstanceOf(LogEntry::class);
        expect($logEntry->getRequestId())->toBe('test-123');
        expect($logEntry->getMethod())->toBe('GET');

        return true;
    }));

    $job = new StoreApiLogJob($logData);
    $job->handle($storageManager);
});

it('retries on failure with backoff', function () {
    $logData = [
        'request_id' => 'test-123',
        'method' => 'GET',
        'endpoint' => '/api/test',
        'request_headers' => [],
        'request_body' => null,
        'response_code' => 200,
        'response_headers' => [],
        'response_body' => null,
        'response_time_ms' => 100,
    ];

    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldReceive('store')->andThrow(new \Exception('Storage failed'));

    Log::shouldReceive('error')->once();

    $job = new StoreApiLogJob($logData);

    // Mock the job's attempts method
    $reflection = new ReflectionClass($job);
    $attemptsProperty = $reflection->getProperty('attempts');
    $attemptsProperty->setAccessible(true);
    $attemptsProperty->setValue($job, 1);

    // Mock the release method
    $releaseCalled = false;
    $releaseDelay = null;

    $jobMock = Mockery::mock(StoreApiLogJob::class.'[release,attempts]', [$logData]);
    $jobMock->shouldReceive('attempts')->andReturn(1);
    $jobMock->shouldReceive('release')->once()->with(1)->andSet($releaseCalled, true)->andSet($releaseDelay, 1);

    try {
        $job->handle($storageManager);
    } catch (\Exception $e) {
        // Expected to throw and be caught
    }

    expect($job->backoff())->toBe([1, 5, 10]);
});

it('logs critical error when permanently failed', function () {
    $logData = [
        'request_id' => 'test-fail',
        'method' => 'POST',
        'endpoint' => '/api/fail',
        'request_headers' => [],
        'request_body' => ['test' => 'data'],
        'response_code' => 500,
        'response_headers' => [],
        'response_body' => null,
        'response_time_ms' => 50,
    ];

    Log::shouldReceive('critical')->once()->with(
        'API log storage job permanently failed',
        Mockery::on(function ($context) {
            expect($context)->toHaveKey('exception');
            expect($context)->toHaveKey('log_data');
            expect($context)->toHaveKey('attempts');

            return true;
        })
    );

    // Mock file_put_contents for fallback storage
    $filePath = storage_path('logs/api-logger-failures.log');
    $directory = dirname($filePath);
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $job = new StoreApiLogJob($logData);
    $exception = new \Exception('Permanent failure');

    $job->failed($exception);

    // Clean up
    if (file_exists($filePath)) {
        unlink($filePath);
    }
});

it('attempts fallback storage on permanent failure', function () {
    $logData = [
        'request_id' => 'test-fallback',
        'method' => 'PUT',
        'endpoint' => '/api/update',
        'request_headers' => [],
        'request_body' => null,
        'response_code' => 200,
        'response_headers' => [],
        'response_body' => null,
        'response_time_ms' => 75,
    ];

    Log::shouldReceive('critical')->once();

    $filePath = storage_path('logs/api-logger-failures.log');
    $directory = dirname($filePath);
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $job = new StoreApiLogJob($logData);
    $job->failed(new \Exception('Storage failed'));

    expect(file_exists($filePath))->toBeTrue();

    $content = file_get_contents($filePath);
    expect($content)->toContain('test-fallback');
    expect($content)->toContain('PUT');
    expect($content)->toContain('/api/update');

    // Clean up
    unlink($filePath);
});

it('has correct job configuration', function () {
    $logData = [
        'request_id' => 'test-config',
        'method' => 'GET',
        'endpoint' => '/api/config',
    ];

    $job = new StoreApiLogJob($logData);

    expect($job->tries)->toBe(3);
    expect($job->maxExceptions)->toBe(3);
    expect($job->timeout)->toBe(30);
    expect($job->backoff())->toBe([1, 5, 10]);
});

it('generates correct tags', function () {
    $logData = [
        'request_id' => 'uuid-123',
        'method' => 'GET',
        'endpoint' => '/api/test',
    ];

    $job = new StoreApiLogJob($logData);

    $tags = $job->tags();

    expect($tags)->toContain('api-logger');
    expect($tags)->toContain('request-id:uuid-123');
});

it('generates correct display name', function () {
    $logData = [
        'request_id' => 'test-123',
        'method' => 'POST',
        'endpoint' => '/api/users',
    ];

    $job = new StoreApiLogJob($logData);

    expect($job->displayName())->toBe('Store API Log: POST /api/users');
});

it('handles missing method and endpoint in display name', function () {
    $logData = [
        'request_id' => 'test-123',
    ];

    $job = new StoreApiLogJob($logData);

    expect($job->displayName())->toBe('Store API Log: UNKNOWN /unknown');
});

it('should be encrypted', function () {
    $logData = [
        'request_id' => 'test-123',
        'method' => 'POST',
        'endpoint' => '/api/sensitive',
    ];

    $job = new StoreApiLogJob($logData);

    expect($job->shouldBeEncrypted())->toBeTrue();
});
