<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Services;

use Ameax\ApiLogger\Models\ApiLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MonitoringService
{
    /**
     * Default cache TTL in seconds.
     */
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Get health status for a specific service.
     *
     * @return array{status: string, success_rate: float, avg_response_time: float, total_requests: int, failed_requests: int, last_request: ?Carbon}
     */
    public function checkServiceHealth(string $service, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? Carbon::now()->subHour();
        $to = $to ?? Carbon::now();

        $cacheKey = "service_health:{$service}:{$from->timestamp}:{$to->timestamp}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($service, $from, $to) {
            $logs = ApiLog::outbound()
                ->forService($service)
                ->betweenDates($from, $to)
                ->select('response_code', 'response_time_ms', 'created_at')
                ->get();

            $totalRequests = $logs->count();
            $failedRequests = $logs->where('response_code', '>=', 400)->count();
            $successRate = $totalRequests > 0 ? (($totalRequests - $failedRequests) / $totalRequests) * 100 : 0;
            $avgResponseTime = $logs->avg('response_time_ms') ?? 0;
            $lastRequest = $logs->max('created_at');

            $status = $this->determineHealthStatus($successRate, $avgResponseTime);

            return [
                'status' => $status,
                'success_rate' => round($successRate, 2),
                'avg_response_time' => round($avgResponseTime, 2),
                'total_requests' => $totalRequests,
                'failed_requests' => $failedRequests,
                'last_request' => $lastRequest,
            ];
        });
    }

    /**
     * Get detailed performance metrics for a service.
     *
     * @return array{avg_response_time: float, min_response_time: float, max_response_time: float, p50: float, p95: float, p99: float, total_requests: int, success_rate: float, error_breakdown: Collection}
     */
    public function getServiceMetrics(string $service, Carbon $from, Carbon $to): array
    {
        $cacheKey = "service_metrics:{$service}:{$from->timestamp}:{$to->timestamp}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($service, $from, $to) {
            $logs = ApiLog::outbound()
                ->forService($service)
                ->betweenDates($from, $to)
                ->get();

            $responseTimes = $logs->pluck('response_time_ms')->sort()->values();
            $totalRequests = $logs->count();

            if ($totalRequests === 0) {
                return $this->emptyMetrics();
            }

            $successfulRequests = $logs->where('response_code', '<', 400)->count();
            $successRate = ($successfulRequests / $totalRequests) * 100;

            // Calculate percentiles
            $p50 = $this->calculatePercentile($responseTimes, 50);
            $p95 = $this->calculatePercentile($responseTimes, 95);
            $p99 = $this->calculatePercentile($responseTimes, 99);

            // Error breakdown
            $errorBreakdown = $logs->where('response_code', '>=', 400)
                ->groupBy('response_code')
                ->map(fn ($group) => $group->count());

            return [
                'avg_response_time' => round($responseTimes->avg(), 2),
                'min_response_time' => round($responseTimes->min(), 2),
                'max_response_time' => round($responseTimes->max(), 2),
                'p50' => round($p50, 2),
                'p95' => round($p95, 2),
                'p99' => round($p99, 2),
                'total_requests' => $totalRequests,
                'success_rate' => round($successRate, 2),
                'error_breakdown' => $errorBreakdown,
            ];
        });
    }

    /**
     * Get retry statistics for a service.
     *
     * @return array{total_retries: int, retry_success_rate: float, max_retry_attempts: int, avg_retry_attempts: float, failed_after_retries: int}
     */
    public function getRetryStatistics(string $service, Carbon $from, Carbon $to): array
    {
        $cacheKey = "retry_stats:{$service}:{$from->timestamp}:{$to->timestamp}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($service, $from, $to) {
            // Get all logs with retries for this service
            $retriedLogs = ApiLog::outbound()
                ->forService($service)
                ->withRetries()
                ->betweenDates($from, $to)
                ->get();

            if ($retriedLogs->isEmpty()) {
                return [
                    'total_retries' => 0,
                    'retry_success_rate' => 0,
                    'max_retry_attempts' => 0,
                    'avg_retry_attempts' => 0,
                    'failed_after_retries' => 0,
                ];
            }

            // Group by correlation ID to find retry chains
            $retryChains = $retriedLogs->groupBy('correlation_identifier')->filter(fn ($chain) => $chain->count() > 1);

            $totalRetries = $retriedLogs->count();
            $successfulRetries = 0;
            $failedAfterRetries = 0;
            $maxAttempts = 0;
            $totalAttempts = 0;

            foreach ($retryChains as $chain) {
                $lastAttempt = $chain->sortByDesc('retry_attempt')->first();
                $maxAttempts = max($maxAttempts, $lastAttempt->retry_attempt);
                $totalAttempts += $lastAttempt->retry_attempt;

                if ($lastAttempt->response_code < 400) {
                    $successfulRetries++;
                } else {
                    $failedAfterRetries++;
                }
            }

            $retrySuccessRate = $retryChains->count() > 0
                ? ($successfulRetries / $retryChains->count()) * 100
                : 0;

            $avgRetryAttempts = $retryChains->count() > 0
                ? $totalAttempts / $retryChains->count()
                : 0;

            return [
                'total_retries' => $totalRetries,
                'retry_success_rate' => round($retrySuccessRate, 2),
                'max_retry_attempts' => $maxAttempts,
                'avg_retry_attempts' => round($avgRetryAttempts, 2),
                'failed_after_retries' => $failedAfterRetries,
            ];
        });
    }

    /**
     * Get correlation chain analysis.
     */
    public function analyzeCorrelationChain(string $correlationId): Collection
    {
        $logs = ApiLog::withCorrelation($correlationId)
            ->orderBy('created_at')
            ->get();

        return $logs->map(function ($log) use ($logs) {
            return [
                'request_id' => (string) $log->id,
                'direction' => $log->direction,
                'service' => $log->service,
                'endpoint' => $log->endpoint,
                'method' => $log->method,
                'response_code' => $log->response_code,
                'response_time_ms' => $log->response_time_ms,
                'retry_attempt' => $log->retry_attempt,
                'created_at' => $log->created_at,
                'time_since_first' => $logs->isNotEmpty() ? (int) $logs->first()->created_at->diffInMilliseconds($log->created_at) : 0,
            ];
        });
    }

    /**
     * Detect anomalies in service performance.
     *
     * @return array{has_anomaly: bool, type: ?string, current_value: ?float, threshold: ?float, deviation: ?float}
     */
    public function detectAnomalies(string $service, ?array $thresholds = null): array
    {
        $thresholds = $thresholds ?? $this->getDefaultThresholds();

        // Get recent metrics
        $recentMetrics = $this->getServiceMetrics(
            $service,
            Carbon::now()->subHour(),
            Carbon::now()
        );

        // Get baseline metrics (last 24 hours excluding recent hour)
        $baselineMetrics = $this->getServiceMetrics(
            $service,
            Carbon::now()->subDay(),
            Carbon::now()->subHour()
        );

        // Check for response time anomaly
        if ($baselineMetrics['avg_response_time'] > 0 && $recentMetrics['avg_response_time'] > $baselineMetrics['avg_response_time'] * $thresholds['response_time_multiplier']) {
            $deviation = (($recentMetrics['avg_response_time'] - $baselineMetrics['avg_response_time']) / $baselineMetrics['avg_response_time']) * 100;

            return [
                'has_anomaly' => true,
                'type' => 'high_response_time',
                'current_value' => $recentMetrics['avg_response_time'],
                'threshold' => $baselineMetrics['avg_response_time'] * $thresholds['response_time_multiplier'],
                'deviation' => round($deviation, 2),
            ];
        }

        // Check for error rate anomaly
        if ($recentMetrics['success_rate'] < $thresholds['min_success_rate']) {
            return [
                'has_anomaly' => true,
                'type' => 'low_success_rate',
                'current_value' => $recentMetrics['success_rate'],
                'threshold' => $thresholds['min_success_rate'],
                'deviation' => $thresholds['min_success_rate'] - $recentMetrics['success_rate'],
            ];
        }

        return [
            'has_anomaly' => false,
            'type' => null,
            'current_value' => null,
            'threshold' => null,
            'deviation' => null,
        ];
    }

    /**
     * Calculate service availability percentage.
     */
    public function calculateAvailability(string $service, Carbon $from, Carbon $to): float
    {
        $totalRequests = ApiLog::outbound()
            ->forService($service)
            ->betweenDates($from, $to)
            ->count();

        if ($totalRequests === 0) {
            return 100.0; // No requests means service wasn't used, consider it available
        }

        $successfulRequests = ApiLog::outbound()
            ->forService($service)
            ->betweenDates($from, $to)
            ->where('response_code', '<', 500)
            ->count();

        return round(($successfulRequests / $totalRequests) * 100, 2);
    }

    /**
     * Get aggregated metrics for all services.
     */
    public function getAllServicesMetrics(Carbon $from, Carbon $to): Collection
    {
        $services = ApiLog::outbound()
            ->betweenDates($from, $to)
            ->get()
            ->pluck('service')
            ->unique()
            ->filter();

        return $services->map(function ($service) use ($from, $to) {
            return [
                'service' => $service,
                'metrics' => $this->getServiceMetrics($service, $from, $to),
                'health' => $this->checkServiceHealth($service, $from, $to),
                'availability' => $this->calculateAvailability($service, $from, $to),
            ];
        });
    }

    /**
     * Get peak usage times for a service.
     */
    public function getPeakUsageTimes(string $service, Carbon $from, Carbon $to, string $interval = 'hour'): Collection
    {
        // Use strftime for SQLite compatibility
        $format = match ($interval) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-%W',
            default => '%Y-%m-%d %H:00:00',
        };

        // Check if we're using SQLite
        $driver = DB::connection()->getDriverName();
        $dateFormatSql = $driver === 'sqlite'
            ? "strftime('$format', created_at)"
            : "DATE_FORMAT(created_at, '$format')";

        $results = DB::table('api_logs')
            ->where('service', $service)
            ->where('direction', 'outbound')
            ->whereBetween('created_at', [$from, $to])
            ->select(
                DB::raw("$dateFormatSql as time_bucket"),
                DB::raw('COUNT(*) as request_count'),
                DB::raw('AVG(response_time_ms) as avg_response_time')
            )
            ->groupBy('time_bucket')
            ->orderBy('request_count', 'desc')
            ->limit(10)
            ->get();

        return collect($results);
    }

    /**
     * Calculate percentile value from a sorted collection.
     */
    private function calculatePercentile(Collection $values, int $percentile): float
    {
        if ($values->isEmpty()) {
            return 0;
        }

        $index = ($percentile / 100) * ($values->count() - 1);
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower === $upper) {
            return $values[(int) $lower];
        }

        $lowerValue = $values[(int) $lower];
        $upperValue = $values[(int) $upper];
        $fraction = $index - $lower;

        return $lowerValue + ($upperValue - $lowerValue) * $fraction;
    }

    /**
     * Determine health status based on metrics.
     */
    private function determineHealthStatus(float $successRate, float $avgResponseTime): string
    {
        if ($successRate >= 99 && $avgResponseTime < 1000) {
            return 'healthy';
        }

        if ($successRate >= 95 && $avgResponseTime < 3000) {
            return 'degraded';
        }

        if ($successRate >= 80 && $avgResponseTime < 5000) {
            return 'degraded';
        }

        if ($successRate >= 70) {
            return 'warning';
        }

        return 'critical';
    }

    /**
     * Get default anomaly detection thresholds.
     *
     * @return array{response_time_multiplier: float, min_success_rate: float}
     */
    private function getDefaultThresholds(): array
    {
        return [
            'response_time_multiplier' => 2.0, // Alert if response time is 2x baseline
            'min_success_rate' => 95.0, // Alert if success rate drops below 95%
        ];
    }

    /**
     * Return empty metrics structure.
     *
     * @return array{avg_response_time: float, min_response_time: float, max_response_time: float, p50: float, p95: float, p99: float, total_requests: int, success_rate: float, error_breakdown: Collection}
     */
    private function emptyMetrics(): array
    {
        return [
            'avg_response_time' => 0.0,
            'min_response_time' => 0.0,
            'max_response_time' => 0.0,
            'p50' => 0.0,
            'p95' => 0.0,
            'p99' => 0.0,
            'total_requests' => 0,
            'success_rate' => 0.0,
            'error_breakdown' => collect(),
        ];
    }
}
