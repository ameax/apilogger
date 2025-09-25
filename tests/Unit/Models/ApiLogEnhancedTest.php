<?php

declare(strict_types=1);

use Ameax\ApiLogger\Models\ApiLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2025-01-25 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('ApiLog Enhanced Scopes', function () {
    it('filters inbound requests', function () {
        // Create inbound logs (default)
        $log1 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 100,
            'direction' => 'inbound',
        ]);

        // Create another inbound log
        $log2 = ApiLog::create([
            'method' => 'GET',
            'endpoint' => '/api/test2',
            'response_code' => 200,
            'response_time_ms' => 150,
            'direction' => 'inbound',
        ]);

        // Create outbound log
        $log3 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://external.api/endpoint',
            'response_code' => 200,
            'response_time_ms' => 500,
            'direction' => 'outbound',
        ]);

        $inboundLogs = ApiLog::inbound()->get();

        expect($inboundLogs)->toHaveCount(2);
        expect($inboundLogs->pluck('id')->toArray())->toBe([$log1->id, $log2->id]);
    });

    it('filters outbound requests', function () {
        // Create outbound logs
        $log1 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://api.example.com/users',
            'response_code' => 201,
            'response_time_ms' => 250,
            'direction' => 'outbound',
            'service' => 'UserService',
        ]);

        $log2 = ApiLog::create([
            'method' => 'GET',
            'endpoint' => 'https://api.example.com/products',
            'response_code' => 200,
            'response_time_ms' => 180,
            'direction' => 'outbound',
            'service' => 'ProductService',
        ]);

        // Create inbound log
        $log3 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => '/api/internal',
            'response_code' => 200,
            'response_time_ms' => 50,
            'direction' => 'inbound',
        ]);

        $outboundLogs = ApiLog::outbound()->get();

        expect($outboundLogs)->toHaveCount(2);
        expect($outboundLogs->pluck('id')->toArray())->toBe([$log1->id, $log2->id]);
    });

    it('filters logs by service', function () {
        $log1 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://haufe.api/users',
            'response_code' => 200,
            'response_time_ms' => 300,
            'direction' => 'outbound',
            'service' => 'Haufe360ApiService',
        ]);

        ApiLog::create([
            'method' => 'GET',
            'endpoint' => 'https://other.api/data',
            'response_code' => 200,
            'response_time_ms' => 200,
            'direction' => 'outbound',
            'service' => 'OtherService',
        ]);

        $log3 = ApiLog::create([
            'method' => 'PUT',
            'endpoint' => 'https://haufe.api/products',
            'response_code' => 200,
            'response_time_ms' => 250,
            'direction' => 'outbound',
            'service' => 'Haufe360ApiService',
        ]);

        $haufeServiceLogs = ApiLog::forService('Haufe360ApiService')->get();

        expect($haufeServiceLogs)->toHaveCount(2);
        expect($haufeServiceLogs->pluck('id')->toArray())->toBe([$log1->id, $log3->id]);
    });

    it('filters logs by correlation id', function () {
        $correlationId = 'corr-123-abc';

        $log1 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => '/api/order',
            'response_code' => 200,
            'response_time_ms' => 100,
            'correlation_identifier' => $correlationId,
            'direction' => 'inbound',
        ]);

        $log2 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://payment.api/charge',
            'response_code' => 200,
            'response_time_ms' => 500,
            'correlation_identifier' => $correlationId,
            'direction' => 'outbound',
        ]);

        $log3 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://inventory.api/reserve',
            'response_code' => 200,
            'response_time_ms' => 300,
            'correlation_identifier' => $correlationId,
            'direction' => 'outbound',
        ]);

        ApiLog::create([
            'method' => 'GET',
            'endpoint' => '/api/status',
            'response_code' => 200,
            'response_time_ms' => 50,
            'correlation_identifier' => 'other-correlation',
        ]);

        $correlatedLogs = ApiLog::withCorrelation($correlationId)->get();

        expect($correlatedLogs)->toHaveCount(3);
        expect($correlatedLogs->pluck('id')->toArray())->toBe([$log1->id, $log2->id, $log3->id]);
    });

    it('filters failed requests', function () {
        ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 100]);
        ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 404, 'response_time_ms' => 50]);
        ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 500, 'response_time_ms' => 200]);
        ApiLog::create(['method' => 'GET', 'endpoint' => '/test', 'response_code' => 201, 'response_time_ms' => 150]);

        $failedRequests = ApiLog::failedRequests()->get();

        expect($failedRequests)->toHaveCount(2);
        expect($failedRequests->pluck('response_code')->toArray())->toBe([404, 500]);
    });

    it('filters slow requests', function () {
        $log1 = ApiLog::create(['method' => 'GET', 'endpoint' => '/fast', 'response_code' => 200, 'response_time_ms' => 100]);
        $log2 = ApiLog::create(['method' => 'GET', 'endpoint' => '/slow1', 'response_code' => 200, 'response_time_ms' => 5500]);
        $log3 = ApiLog::create(['method' => 'GET', 'endpoint' => '/slow2', 'response_code' => 200, 'response_time_ms' => 10000]);
        $log4 = ApiLog::create(['method' => 'GET', 'endpoint' => '/medium', 'response_code' => 200, 'response_time_ms' => 3000]);

        $slowRequests = ApiLog::slowRequests(5000)->get();

        expect($slowRequests)->toHaveCount(2);
        expect($slowRequests->pluck('id')->toArray())->toBe([$log2->id, $log3->id]);

        $customThreshold = ApiLog::slowRequests(2000)->get();
        expect($customThreshold)->toHaveCount(3);
    });

    it('filters today logs', function () {
        // Create log today
        $todayLog = new ApiLog([
            'method' => 'GET',
            'endpoint' => '/test',
            'response_code' => 200,
            'response_time_ms' => 100,
        ]);
        $todayLog->created_at = Carbon::now();
        $todayLog->save();

        // Create log yesterday
        $yesterdayLog = new ApiLog([
            'method' => 'GET',
            'endpoint' => '/test',
            'response_code' => 200,
            'response_time_ms' => 100,
        ]);
        $yesterdayLog->created_at = Carbon::now()->subDay();
        $yesterdayLog->save();

        $todayLogs = ApiLog::today()->get();

        expect($todayLogs)->toHaveCount(1);
        expect($todayLogs->first()->id)->toBe($todayLog->id);
    });

    it('filters logs with retries', function () {
        $log1 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://api.example.com/endpoint',
            'response_code' => 500,
            'response_time_ms' => 1000,
            'retry_attempt' => 1,
            'correlation_identifier' => 'retry-1',
        ]);

        $log2 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://api.example.com/endpoint',
            'response_code' => 200,
            'response_time_ms' => 800,
            'retry_attempt' => 2,
            'correlation_identifier' => 'retry-1',
        ]);

        ApiLog::create([
            'method' => 'GET',
            'endpoint' => 'https://api.example.com/other',
            'response_code' => 200,
            'response_time_ms' => 500,
            'retry_attempt' => 0,
        ]);

        $retriedLogs = ApiLog::withRetries()->get();

        expect($retriedLogs)->toHaveCount(2);
        expect($retriedLogs->pluck('id')->toArray())->toBe([$log1->id, $log2->id]);
    });

    it('filters by specific retry attempt', function () {
        ApiLog::create(['method' => 'POST', 'endpoint' => '/test', 'response_code' => 500, 'response_time_ms' => 1000, 'retry_attempt' => 1]);
        $log2 = ApiLog::create(['method' => 'POST', 'endpoint' => '/test', 'response_code' => 500, 'response_time_ms' => 1000, 'retry_attempt' => 2]);
        ApiLog::create(['method' => 'POST', 'endpoint' => '/test', 'response_code' => 200, 'response_time_ms' => 1000, 'retry_attempt' => 3]);

        $secondAttempt = ApiLog::retryAttempt(2)->get();

        expect($secondAttempt)->toHaveCount(1);
        expect($secondAttempt->first()->id)->toBe($log2->id);
    });
});

describe('ApiLog Accessors', function () {
    it('returns correct direction attribute', function () {
        $inboundLog = ApiLog::create([
            'method' => 'POST',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 100,
            'direction' => 'inbound',
        ]);

        $outboundLog = ApiLog::create([
            'method' => 'GET',
            'endpoint' => 'https://external.api',
            'response_code' => 200,
            'response_time_ms' => 200,
            'direction' => 'outbound',
        ]);

        expect($inboundLog->direction)->toBe('inbound');
        expect($outboundLog->direction)->toBe('outbound');
    });

    it('returns correct service attribute', function () {
        $log = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://haufe.api',
            'response_code' => 200,
            'response_time_ms' => 300,
            'service' => 'Haufe360ApiService',
        ]);

        $noServiceLog = ApiLog::create([
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 100,
        ]);

        expect($log->service)->toBe('Haufe360ApiService');
        expect($noServiceLog->service)->toBeNull();
    });

    it('returns correct correlation id attribute', function () {
        $log = ApiLog::create([
            'method' => 'POST',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 100,
            'correlation_identifier' => 'corr-123-abc',
        ]);

        expect($log->correlation_id)->toBe('corr-123-abc');
        expect($log->correlation_identifier)->toBe('corr-123-abc');
    });

    it('returns correct retry attempt attribute', function () {
        $retriedLog = ApiLog::create([
            'method' => 'POST',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 100,
            'retry_attempt' => 3,
        ]);

        $normalLog = ApiLog::create([
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 50,
            'retry_attempt' => 0,
        ]);

        expect($retriedLog->retry_attempt)->toBe(3);
        expect($normalLog->retry_attempt)->toBe(0);
    });

    it('returns correct boolean attributes', function () {
        $outboundLog = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://external.api',
            'response_code' => 200,
            'response_time_ms' => 200,
            'direction' => 'outbound',
        ]);

        $inboundLog = ApiLog::create([
            'method' => 'GET',
            'endpoint' => '/api/internal',
            'response_code' => 200,
            'response_time_ms' => 100,
            'direction' => 'inbound',
        ]);

        expect($outboundLog->is_outbound)->toBeTrue();
        expect($outboundLog->is_inbound)->toBeFalse();
        expect($inboundLog->is_inbound)->toBeTrue();
        expect($inboundLog->is_outbound)->toBeFalse();
    });

    it('returns correct timing attributes', function () {
        $log = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://external.api',
            'response_code' => 200,
            'response_time_ms' => 500,
            'metadata' => [
                'connection_time_ms' => 150.5,
                'total_time_ms' => 550.8,
            ],
        ]);

        $logWithoutTiming = ApiLog::create([
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 300,
            'metadata' => [],
        ]);

        expect($log->connection_time)->toBe(150.5);
        expect($log->total_time)->toBe(550.8);
        expect($logWithoutTiming->connection_time)->toBeNull();
        expect($logWithoutTiming->total_time)->toBe(300.0);
    });

    it('returns correct was retried attribute', function () {
        $retriedLog = ApiLog::create([
            'method' => 'POST',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 100,
            'retry_attempt' => 2,
        ]);

        $normalLog = ApiLog::create([
            'method' => 'GET',
            'endpoint' => '/api/test',
            'response_code' => 200,
            'response_time_ms' => 50,
            'retry_attempt' => 0,
        ]);

        expect($retriedLog->was_retried)->toBeTrue();
        expect($normalLog->was_retried)->toBeFalse();
    });

    it('retrieves correlation chain', function () {
        $correlationId = 'corr-chain-123';

        // Create a chain of correlated logs
        $log1 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => '/api/order',
            'response_code' => 200,
            'response_time_ms' => 100,
            'correlation_identifier' => $correlationId,
            'direction' => 'inbound',
            'created_at' => Carbon::now()->subMinutes(3),
        ]);

        $log2 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://payment.api',
            'response_code' => 200,
            'response_time_ms' => 500,
            'correlation_identifier' => $correlationId,
            'direction' => 'outbound',
            'created_at' => Carbon::now()->subMinutes(2),
        ]);

        $log3 = ApiLog::create([
            'method' => 'POST',
            'endpoint' => 'https://inventory.api',
            'response_code' => 200,
            'response_time_ms' => 300,
            'correlation_identifier' => $correlationId,
            'direction' => 'outbound',
            'created_at' => Carbon::now()->subMinute(),
        ]);

        // Create unrelated log
        ApiLog::create([
            'method' => 'GET',
            'endpoint' => '/api/other',
            'response_code' => 200,
            'response_time_ms' => 50,
            'correlation_identifier' => 'other-correlation',
        ]);

        $chain = $log2->getCorrelationChain();

        expect($chain)->toHaveCount(3);
        expect($chain->pluck('id')->toArray())->toBe([$log1->id, $log2->id, $log3->id]);

        // Test log without correlation ID
        $standaloneLog = ApiLog::create([
            'method' => 'GET',
            'endpoint' => '/api/standalone',
            'response_code' => 200,
            'response_time_ms' => 75,
        ]);

        $standaloneChain = $standaloneLog->getCorrelationChain();
        expect($standaloneChain)->toHaveCount(1);
        expect($standaloneChain->first()->id)->toBe($standaloneLog->id);
    });
});
