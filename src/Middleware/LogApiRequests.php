<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Middleware;

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Jobs\StoreApiLogJob;
use Ameax\ApiLogger\Services\DataSanitizer;
use Ameax\ApiLogger\Services\FilterService;
use Ameax\ApiLogger\Services\RequestCapture;
use Ameax\ApiLogger\Services\ResponseCapture;
use Ameax\ApiLogger\StorageManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogApiRequests
{
    protected StorageManager $storageManager;

    protected DataSanitizer $sanitizer;

    protected FilterService $filterService;

    protected RequestCapture $requestCapture;

    protected ResponseCapture $responseCapture;

    protected array $config;

    protected bool $circuitBreakerOpen = false;

    protected int $failureCount = 0;

    protected ?float $circuitBreakerOpenedAt = null;

    protected const CIRCUIT_BREAKER_THRESHOLD = 5;

    protected const CIRCUIT_BREAKER_TIMEOUT = 60; // seconds

    public function __construct(
        StorageManager $storageManager,
        DataSanitizer $sanitizer,
        FilterService $filterService,
        RequestCapture $requestCapture,
        ResponseCapture $responseCapture,
        array $config = []
    ) {
        $this->storageManager = $storageManager;
        $this->sanitizer = $sanitizer;
        $this->filterService = $filterService;
        $this->requestCapture = $requestCapture;
        $this->responseCapture = $responseCapture;
        $this->config = $config;
    }

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if logging is enabled
        if (! $this->isEnabled()) {
            return $next($request);
        }

        // Check circuit breaker
        if ($this->isCircuitBreakerOpen()) {
            return $next($request);
        }

        // Record start time for response time calculation
        $startTime = microtime(true);

        try {
            // Capture request data early
            $requestData = $this->requestCapture->capture($request);

            // Process the request
            $response = $next($request);

            // Capture response data
            $responseData = $this->responseCapture->capture($response, $startTime);

            // Check if we should log this request
            if ($this->shouldLog($request, $response, $responseData['response_time_ms'])) {
                $this->logRequest($requestData, $responseData);
            }

            return $response;
        } catch (\Throwable $exception) {
            // Log the error response
            $this->logErrorResponse($request, $exception, $startTime);

            // Re-throw the exception to maintain normal error handling
            throw $exception;
        }
    }

    /**
     * Check if logging is enabled.
     */
    protected function isEnabled(): bool
    {
        if (! ($this->config['enabled'] ?? true)) {
            return false;
        }

        $level = $this->config['level'] ?? 'detailed';

        return $level !== 'none';
    }

    /**
     * Check if the circuit breaker is open.
     */
    protected function isCircuitBreakerOpen(): bool
    {
        if (! $this->circuitBreakerOpen) {
            return false;
        }

        // Check if timeout has passed
        $timeoutPassed = (microtime(true) - $this->circuitBreakerOpenedAt) > self::CIRCUIT_BREAKER_TIMEOUT;

        if ($timeoutPassed) {
            // Try to close the circuit breaker
            $this->circuitBreakerOpen = false;
            $this->failureCount = 0;
            $this->circuitBreakerOpenedAt = null;

            return false;
        }

        return true;
    }

    /**
     * Check if the request should be logged based on filters.
     */
    protected function shouldLog(Request $request, $response, float $responseTime): bool
    {
        // Always log errors if configured
        if ($this->filterService->shouldAlwaysLogErrors() && $response->getStatusCode() >= 400) {
            return true;
        }

        return $this->filterService->shouldLog($request, $response, $responseTime);
    }

    /**
     * Log the request and response data.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    protected function logRequest(array $requestData, array $responseData): void
    {
        try {
            // Sanitize the data
            $sanitizedRequestHeaders = $this->sanitizer->sanitizeHeaders($requestData['headers'] ?? []);
            $sanitizedRequestBody = $this->sanitizer->sanitize($requestData['body'], 'request');
            $sanitizedResponseHeaders = $this->sanitizer->sanitizeHeaders($responseData['headers'] ?? []);
            $sanitizedResponseBody = $this->sanitizer->sanitize($responseData['body'], 'response');

            // Create log entry
            $logEntry = new LogEntry(
                requestId: $requestData['correlation_identifier'] ?? $requestData['request_id'] ?? '',
                method: $requestData['method'],
                endpoint: $requestData['endpoint'],
                requestHeaders: $sanitizedRequestHeaders,
                requestBody: $sanitizedRequestBody,
                responseCode: $responseData['status_code'],
                responseHeaders: $sanitizedResponseHeaders,
                responseBody: $sanitizedResponseBody,
                responseTimeMs: $responseData['response_time_ms'],
                userIdentifier: $requestData['user_identifier'],
                ipAddress: $requestData['ip_address'],
                userAgent: $requestData['user_agent'],
                metadata: array_merge(
                    $requestData['metadata'] ?? [],
                    ['memory_usage' => $responseData['memory_usage'] ?? null]
                ),
            );

            // Store the log entry
            $this->store($logEntry);
        } catch (\Throwable $e) {
            $this->handleStorageFailure($e);
        }
    }

    /**
     * Log an error response when an exception occurs.
     */
    protected function logErrorResponse(Request $request, \Throwable $exception, float $startTime): void
    {
        try {
            // Capture request data
            $requestData = $this->requestCapture->capture($request);

            // Calculate response time
            $responseTimeMs = (microtime(true) - $startTime) * 1000;

            // Prepare error response data
            $errorData = [
                'error' => true,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];

            // Include stack trace only in non-production or if configured
            if (app()->environment('local', 'development') ||
                ($this->config['privacy']['include_stack_traces'] ?? false)) {
                $errorData['stack_trace'] = $exception->getTraceAsString();
            }

            // Create log entry for the error
            $logEntry = new LogEntry(
                requestId: $requestData['correlation_identifier'] ?? $requestData['request_id'] ?? '',
                method: $requestData['method'],
                endpoint: $requestData['endpoint'],
                requestHeaders: $this->sanitizer->sanitizeHeaders($requestData['headers'] ?? []),
                requestBody: $this->sanitizer->sanitize($requestData['body'], 'request'),
                responseCode: 500, // Default to 500 for unhandled exceptions
                responseHeaders: [],
                responseBody: $errorData,
                responseTimeMs: round($responseTimeMs, 2),
                userIdentifier: $requestData['user_identifier'],
                ipAddress: $requestData['ip_address'],
                userAgent: $requestData['user_agent'],
                metadata: array_merge(
                    $requestData['metadata'] ?? [],
                    ['error_logged' => true]
                ),
            );

            // Store the error log
            $this->store($logEntry);
        } catch (\Throwable $e) {
            // If we can't log the error, log to Laravel's error log
            Log::error('Failed to log API error response', [
                'original_exception' => $exception->getMessage(),
                'logging_exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store the log entry using the configured storage method.
     */
    protected function store(LogEntry $logEntry): void
    {
        // Check circuit breaker status first
        if ($this->isCircuitBreakerOpen()) {
            Log::debug('Circuit breaker is open, skipping storage', [
                'request_id' => $logEntry->getRequestId(),
                'failure_count' => $this->failureCount,
            ]);

            return; // Skip storage if circuit breaker is open
        }

        // Check if we should use queue
        if ($this->shouldUseQueue()) {
            // Dispatch to queue
            StoreApiLogJob::dispatch($logEntry->toArray())
                ->onQueue($this->config['performance']['queue_name'] ?? 'default');
        } else {
            // Store synchronously with timeout protection
            $this->storeWithTimeout($logEntry);
        }
    }

    /**
     * Store log entry with timeout protection.
     */
    protected function storeWithTimeout(LogEntry $logEntry): void
    {
        $timeout = ($this->config['performance']['timeout'] ?? 1000) / 1000; // Convert to seconds
        $stored = false;
        $exception = null;

        // Use a simple timeout mechanism
        $startTime = microtime(true);

        try {
            $storage = $this->storageManager->driver();
            $storage->store($logEntry);
            $stored = true;
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $elapsed = microtime(true) - $startTime;

        if (! $stored || $elapsed > $timeout) {
            $this->handleStorageFailure($exception ?? new \RuntimeException('Storage operation timed out'));
        } else {
            // Reset failure count on success
            $this->failureCount = 0;
        }
    }

    /**
     * Check if queue should be used for storing logs.
     */
    protected function shouldUseQueue(): bool
    {
        // Don't use queue if circuit breaker is open
        if ($this->circuitBreakerOpen) {
            return false;
        }

        return $this->config['performance']['use_queue'] ?? false;
    }

    /**
     * Handle storage failure and manage circuit breaker.
     */
    protected function handleStorageFailure(\Throwable $exception): void
    {
        $this->failureCount++;

        // Log the failure
        Log::warning('API logger storage failed', [
            'exception' => $exception->getMessage(),
            'failure_count' => $this->failureCount,
            'circuit_breaker_open' => $this->circuitBreakerOpen,
        ]);

        // Open circuit breaker if threshold reached
        if ($this->failureCount >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $this->circuitBreakerOpen = true;
            $this->circuitBreakerOpenedAt = microtime(true);

            Log::error('API logger circuit breaker opened due to repeated failures', [
                'failure_count' => $this->failureCount,
            ]);
        }
    }

    /**
     * Set a custom filter callback.
     */
    public function filter(\Closure $callback): self
    {
        $this->filterService->addCustomFilter($callback);

        return $this;
    }

    /**
     * Set routes to include in logging.
     *
     * @param  array<string>  $routes
     */
    public function includeRoutes(array $routes): self
    {
        $this->filterService->includeRoutes($routes);

        return $this;
    }

    /**
     * Set routes to exclude from logging.
     *
     * @param  array<string>  $routes
     */
    public function excludeRoutes(array $routes): self
    {
        $this->filterService->excludeRoutes($routes);

        return $this;
    }

    /**
     * Set HTTP methods to include in logging.
     *
     * @param  array<string>  $methods
     */
    public function includeMethods(array $methods): self
    {
        $this->filterService->includeMethods($methods);

        return $this;
    }

    /**
     * Set HTTP methods to exclude from logging.
     *
     * @param  array<string>  $methods
     */
    public function excludeMethods(array $methods): self
    {
        $this->filterService->excludeMethods($methods);

        return $this;
    }

    /**
     * Reset the circuit breaker (for testing purposes).
     */
    public function resetCircuitBreaker(): void
    {
        $this->circuitBreakerOpen = false;
        $this->failureCount = 0;
        $this->circuitBreakerOpenedAt = null;
    }
}
