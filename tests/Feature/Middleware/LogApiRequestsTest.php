<?php

declare(strict_types=1);

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Jobs\StoreApiLogJob;
use Ameax\ApiLogger\Middleware\LogApiRequests;
use Ameax\ApiLogger\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['apilogger.enabled' => true]);
    config(['apilogger.level' => 'full']);
    config(['apilogger.performance.use_queue' => false]);
    config(['apilogger.filters.include_routes' => []]);
    config(['apilogger.filters.exclude_routes' => []]);
});

it('captures request and response data', function () {
    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldReceive('store')->once()->with(Mockery::on(function ($logEntry) {
        expect($logEntry)->toBeInstanceOf(LogEntry::class);
        expect($logEntry->getMethod())->toBe('POST');
        expect($logEntry->getEndpoint())->toBe('/api/test');
        expect($logEntry->getResponseCode())->toBe(200);
        expect($logEntry->getRequestBody())->toBe(['test' => 'data']);
        expect($logEntry->getResponseBody())->toBe(['success' => true]);

        return true;
    }));

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/api/test', 'POST', [], [], [], [], json_encode(['test' => 'data']));
    $request->headers->set('Content-Type', 'application/json');

    $response = null;
    $next = function ($req) use (&$response) {
        $response = new Response(json_encode(['success' => true]), 200);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    };

    $result = $middleware->handle($request, $next);

    expect($result)->toBe($response);
});

it('sanitizes sensitive data before logging', function () {
    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldReceive('store')->once()->with(Mockery::on(function ($logEntry) {
        $requestBody = $logEntry->getRequestBody();
        expect($requestBody['password'])->toBe('[REDACTED]');
        expect($requestBody['username'])->toBe('john');
        expect($requestBody['email'])->toContain('**');

        return true;
    }));

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/api/login', 'POST', [
        'username' => 'john',
        'password' => 'secret123',
        'email' => 'john@example.com',
    ]);

    $next = function ($req) {
        return new Response(json_encode(['authenticated' => true]), 200);
    };

    $middleware->handle($request, $next);
});

it('respects route filters', function () {
    config(['apilogger.filters.exclude_routes' => ['health/*']]);

    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldNotReceive('store');

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/health/check', 'GET');
    $next = function ($req) {
        return new Response('OK', 200);
    };

    $middleware->handle($request, $next);
});

it('respects method filters', function () {
    config(['apilogger.filters.exclude_methods' => ['OPTIONS']]);

    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldNotReceive('store');

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/api/test', 'OPTIONS');
    $next = function ($req) {
        return new Response('', 200);
    };

    $middleware->handle($request, $next);
});

it('respects status code filters', function () {
    config(['apilogger.filters.exclude_status_codes' => [304]]);

    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldNotReceive('store');

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/api/test', 'GET');
    $next = function ($req) {
        return new Response('', 304);
    };

    $middleware->handle($request, $next);
});

it('respects response time filter', function () {
    config(['apilogger.filters.min_response_time' => 1000]); // 1 second

    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldNotReceive('store'); // Fast response should not be logged

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/api/fast', 'GET');
    $next = function ($req) {
        return new Response('Fast', 200);
    };

    $middleware->handle($request, $next);
});

it('always logs errors when configured', function () {
    config(['apilogger.filters.always_log_errors' => true]);
    config(['apilogger.filters.exclude_routes' => ['error/*']]);

    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldReceive('store')->once(); // Should log despite route filter

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/error/test', 'GET');
    $next = function ($req) {
        return new Response('Error', 500);
    };

    $middleware->handle($request, $next);
});

it('uses queue when configured', function () {
    config(['apilogger.performance.use_queue' => true]);
    config(['apilogger.performance.queue_name' => 'logs']);

    Queue::fake();

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/api/test', 'POST', ['data' => 'test']);
    $next = function ($req) {
        return new Response('OK', 200);
    };

    $middleware->handle($request, $next);

    Queue::assertPushed(StoreApiLogJob::class, function ($job) {
        return true;
    });

    Queue::assertPushedOn('logs', StoreApiLogJob::class);
});

it('handles exceptions during request processing', function () {
    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldReceive('store')->once()->with(Mockery::on(function ($logEntry) {
        expect($logEntry->getResponseCode())->toBe(500);
        $responseBody = $logEntry->getResponseBody();
        expect($responseBody['error'])->toBe(true);
        expect($responseBody['message'])->toBe('Something went wrong');

        return true;
    }));

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/api/error', 'GET');
    $next = function ($req) {
        throw new \RuntimeException('Something went wrong');
    };

    expect(fn () => $middleware->handle($request, $next))
        ->toThrow(\RuntimeException::class, 'Something went wrong');
});

it('implements circuit breaker pattern for storage failures', function () {
    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);

    // First 5 calls will fail and open the circuit breaker
    $storage->shouldReceive('store')
        ->atMost()
        ->times(5)
        ->andThrow(new \Exception('Storage failed'));

    app()->instance(StorageManager::class, $storageManager);

    // Create a single middleware instance to preserve state
    $middleware = new LogApiRequests(
        $storageManager,
        app(\Ameax\ApiLogger\Services\DataSanitizer::class),
        app(\Ameax\ApiLogger\Services\FilterService::class),
        app(\Ameax\ApiLogger\Services\RequestCapture::class),
        app(\Ameax\ApiLogger\Services\ResponseCapture::class),
        config('apilogger')
    );

    // Send 5 requests that will fail
    for ($i = 0; $i < 5; $i++) {
        $request = Request::create('/api/test', 'GET');
        $next = function ($req) {
            return new Response('OK', 200);
        };

        $middleware->handle($request, $next);
    }

    // After 5 failures, circuit breaker should be open
    // Next request should not attempt to store
    $request = Request::create('/api/test', 'GET');
    $next = function ($req) {
        return new Response('OK', 200);
    };

    $middleware->handle($request, $next);

    // Verify circuit breaker is working by checking it didn't call store again
    // The mock will fail if store is called more than 5 times
});

it('does not log when disabled', function () {
    config(['apilogger.enabled' => false]);

    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldNotReceive('store');

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/api/test', 'GET');
    $next = function ($req) {
        return new Response('OK', 200);
    };

    $middleware->handle($request, $next);
});

it('does not capture body when level is not full', function () {
    config(['apilogger.level' => 'basic']);

    $storageManager = Mockery::mock(StorageManager::class);
    $storage = Mockery::mock(\Ameax\ApiLogger\Contracts\StorageInterface::class);

    $storageManager->shouldReceive('driver')->andReturn($storage);
    $storage->shouldReceive('store')->once()->with(Mockery::on(function ($logEntry) {
        expect($logEntry->getRequestBody())->toBeNull();
        expect($logEntry->getResponseBody())->toBeNull();

        return true;
    }));

    app()->instance(StorageManager::class, $storageManager);

    $middleware = app(LogApiRequests::class);

    $request = Request::create('/api/test', 'POST', ['data' => 'test']);
    $next = function ($req) {
        return new Response(json_encode(['result' => 'ok']), 200);
    };

    $middleware->handle($request, $next);
});
