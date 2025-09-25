<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Outbound;

use Ameax\ApiLogger\Support\CorrelationIdManager;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\App;

class GuzzleHandlerStackFactory
{
    /**
     * Create a new Guzzle handler stack with API logging middleware.
     */
    public static function create(
        ?string $serviceClass = null,
        ?HandlerStack $stack = null,
        array $config = []
    ): HandlerStack {
        $stack = $stack ?? HandlerStack::create();

        // Check if outbound logging is enabled
        $appConfig = config('apilogger', []);
        if (! ($appConfig['features']['outbound']['enabled'] ?? false)) {
            return $stack;
        }

        // Check if service is registered and should be logged
        if ($serviceClass && ! ServiceRegistry::shouldLog($serviceClass)) {
            return $stack;
        }

        // Get the outbound logger
        $logger = App::make(\Ameax\ApiLogger\Contracts\OutboundLoggerInterface::class);

        // Create correlation ID manager if enabled
        $correlationIdManager = null;
        if ($appConfig['features']['correlation']['enabled'] ?? true) {
            $correlationIdManager = new CorrelationIdManager($appConfig);
        }

        // Create service detector
        $serviceDetector = $serviceClass ? null : new ServiceDetector;

        // Create and add the middleware
        $middleware = new GuzzleLoggerMiddleware(
            $logger,
            $correlationIdManager,
            $serviceDetector
        );

        // Add middleware to stack
        $stack->push(Middleware::tap(null, null), 'api_logger_prepare');
        $stack->push($middleware, 'api_logger');

        // Add retry middleware if configured
        if ($config['retry'] ?? false) {
            $stack->push(Middleware::retry(
                self::createRetryDecider($config),
                self::createRetryDelay($config)
            ), 'api_logger_retry');
        }

        return $stack;
    }

    /**
     * Create a handler stack for a specific service.
     */
    public static function createForService(
        string $serviceClass,
        ?HandlerStack $stack = null
    ): HandlerStack {
        // Get service-specific configuration
        $serviceConfig = ServiceRegistry::getConfig($serviceClass) ?? [];

        return self::create($serviceClass, $stack, $serviceConfig);
    }

    /**
     * Add logging middleware to an existing handler stack.
     */
    public static function addToStack(
        HandlerStack $stack,
        ?string $serviceClass = null
    ): HandlerStack {
        return self::create($serviceClass, $stack);
    }

    /**
     * Create retry decider function.
     */
    private static function createRetryDecider(array $config): callable
    {
        $maxRetries = $config['retry_max_attempts'] ?? 3;
        $retryOnStatuses = $config['retry_on_statuses'] ?? [500, 502, 503, 504];

        return function (
            int $retries,
            $request,
            $response,
            $exception
        ) use ($maxRetries, $retryOnStatuses) {
            // Don't retry if we've exceeded max attempts
            if ($retries >= $maxRetries) {
                return false;
            }

            // Retry on connection exceptions
            if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                return true;
            }

            // Retry on specific status codes
            if ($response && in_array($response->getStatusCode(), $retryOnStatuses)) {
                return true;
            }

            return false;
        };
    }

    /**
     * Create retry delay function.
     */
    private static function createRetryDelay(array $config): callable
    {
        $baseDelay = $config['retry_base_delay_ms'] ?? 1000;
        $multiplier = $config['retry_multiplier'] ?? 2;

        return function (int $numberOfRetries) use ($baseDelay, $multiplier) {
            return $baseDelay * pow($multiplier, $numberOfRetries - 1);
        };
    }

    /**
     * Create a basic handler stack without any middleware.
     */
    public static function createBasic(): HandlerStack
    {
        return HandlerStack::create();
    }

    /**
     * Check if logging is enabled for a given service.
     */
    public static function isLoggingEnabled(?string $serviceClass = null): bool
    {
        $config = config('apilogger', []);

        if (! ($config['features']['outbound']['enabled'] ?? false)) {
            return false;
        }

        if ($serviceClass) {
            return ServiceRegistry::shouldLog($serviceClass);
        }

        return true;
    }
}
