<?php

/**
 * Retry Handling and Error Recovery Example
 *
 * This example demonstrates how to implement retry logic with proper tracking
 * and error handling for resilient API integrations.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Ameax\ApiLogger\Models\ApiLog;
use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

echo "=== Retry Handling with API Logging ===\n\n";

// Create a handler stack with logging
$stack = GuzzleHandlerStackFactory::create();

// Add retry middleware with custom logic
$retryMiddleware = Middleware::retry(
    function (
        $retries,
        Request $request,
        ?Response $response = null,
        ?RequestException $exception = null
    ) {
        echo "Retry attempt: {$retries}\n";

        // Maximum retry attempts
        if ($retries >= 3) {
            echo "  - Maximum retries reached. Giving up.\n";

            return false;
        }

        // Retry on connection errors
        if ($exception instanceof ConnectException) {
            echo "  - Connection error. Will retry...\n";

            return true;
        }

        // Retry on server errors (5xx)
        if ($response && $response->getStatusCode() >= 500) {
            echo "  - Server error ({$response->getStatusCode()}). Will retry...\n";

            return true;
        }

        // Retry on specific 4xx errors (rate limiting)
        if ($response && $response->getStatusCode() === 429) {
            echo "  - Rate limited. Will retry after delay...\n";

            return true;
        }

        // Don't retry other errors
        return false;
    },
    function ($retries) {
        // Exponential backoff with jitter
        $delay = 1000 * (2 ** $retries) + rand(0, 1000);
        echo "  - Waiting {$delay}ms before retry...\n";

        return $delay;
    }
);

$stack->push($retryMiddleware, 'retry');

// Create client with retry-enabled stack
$client = new Client([
    'handler' => $stack,
    'timeout' => 5,
]);

// Example 1: Successful retry after transient error
echo "Example 1: Simulating transient error (httpstat.us)\n";
try {
    // This endpoint randomly returns 500 errors
    $response = $client->get('https://httpstat.us/500?sleep=100');
    echo "Request succeeded!\n";
} catch (RequestException $e) {
    echo 'Request failed after retries: '.$e->getMessage()."\n";
}

echo "\n".str_repeat('-', 50)."\n\n";

// Example 2: Rate limiting with backoff
echo "Example 2: Rate limiting scenario\n";

$rateLimitedClient = new Client([
    'handler' => $stack,
    'base_uri' => 'https://httpstat.us/',
]);

// Simulate multiple requests that might trigger rate limiting
$endpoints = [
    '200?sleep=100', // Success
    '429?sleep=100', // Rate limited
    '500?sleep=100', // Server error
    '200?sleep=100', // Success
];

foreach ($endpoints as $index => $endpoint) {
    echo "\nRequest ".($index + 1).": {$endpoint}\n";
    try {
        $response = $rateLimitedClient->get($endpoint);
        echo '  - Success: '.$response->getStatusCode()."\n";
    } catch (RequestException $e) {
        echo '  - Failed: '.$e->getMessage()."\n";
    }
}

echo "\n".str_repeat('-', 50)."\n\n";

// Example 3: Circuit breaker pattern
echo "Example 3: Circuit Breaker Implementation\n";

class CircuitBreaker
{
    private static $failures = [];

    private static $openCircuits = [];

    private const FAILURE_THRESHOLD = 3;

    private const TIMEOUT = 60; // seconds

    public static function call($service, callable $operation)
    {
        // Check if circuit is open
        if (self::isOpen($service)) {
            echo "  - Circuit is OPEN for {$service}. Skipping call.\n";
            throw new \Exception("Circuit breaker is open for {$service}");
        }

        try {
            $result = $operation();
            self::onSuccess($service);
            echo "  - Circuit is CLOSED. Call succeeded.\n";

            return $result;
        } catch (\Exception $e) {
            self::onFailure($service);

            if (self::isOpen($service)) {
                echo "  - Circuit is now OPEN after {self::$failures[$service]} failures.\n";
            }

            throw $e;
        }
    }

    private static function isOpen($service)
    {
        if (! isset(self::$openCircuits[$service])) {
            return false;
        }

        // Check if timeout has passed (circuit half-open)
        if (time() - self::$openCircuits[$service] > self::TIMEOUT) {
            echo "  - Circuit timeout expired. Trying half-open state.\n";
            unset(self::$openCircuits[$service]);

            return false;
        }

        return true;
    }

    private static function onSuccess($service)
    {
        self::$failures[$service] = 0;
        unset(self::$openCircuits[$service]);
    }

    private static function onFailure($service)
    {
        if (! isset(self::$failures[$service])) {
            self::$failures[$service] = 0;
        }

        self::$failures[$service]++;

        if (self::$failures[$service] >= self::FAILURE_THRESHOLD) {
            self::$openCircuits[$service] = time();
        }
    }
}

// Test circuit breaker with unreliable service
$unreliableClient = new Client([
    'handler' => $stack,
    'timeout' => 2,
]);

for ($i = 1; $i <= 5; $i++) {
    echo "\nAttempt {$i}:\n";
    try {
        $response = CircuitBreaker::call('unreliable-api', function () use ($unreliableClient) {
            // This will fail most of the time
            return $unreliableClient->get('https://httpstat.us/500?sleep=100');
        });
        echo "  - Success!\n";
    } catch (\Exception $e) {
        echo '  - Failed: '.substr($e->getMessage(), 0, 50)."...\n";
    }
    sleep(1);
}

echo "\n".str_repeat('-', 50)."\n\n";

// Example 4: Analyzing retry patterns
echo "Example 4: Analyzing Retry Patterns\n\n";

// Create some test data with retries
$testClient = new Client([
    'handler' => $stack,
    'base_uri' => 'https://jsonplaceholder.typicode.com',
]);

// Make several requests (some will be retried)
$urls = ['/posts/1', '/posts/2', '/posts/999', '/users/1'];
foreach ($urls as $url) {
    try {
        $testClient->get($url);
    } catch (\Exception $e) {
        // Ignore errors for this example
    }
}

// Analyze retry patterns
echo "Retry Analysis:\n";

// Get logs with retries
$retryLogs = ApiLog::outbound()
    ->where('retry_attempt', '>', 0)
    ->latest()
    ->take(10)
    ->get();

if ($retryLogs->isEmpty()) {
    echo "No retry attempts found in recent logs.\n";
} else {
    echo "\nRecent Retry Attempts:\n";
    foreach ($retryLogs as $log) {
        echo sprintf(
            "  - %s %s: Attempt %d, Status %d, Time %dms\n",
            $log->method,
            $log->url,
            $log->retry_attempt,
            $log->response_code,
            $log->response_time
        );
    }

    // Calculate retry statistics
    $totalRetries = $retryLogs->count();
    $successfulRetries = $retryLogs->where('response_code', '<', 400)->count();
    $maxRetries = $retryLogs->max('retry_attempt');
    $avgRetryTime = $retryLogs->avg('response_time');

    echo "\nRetry Statistics:\n";
    echo "  - Total retries: {$totalRetries}\n";
    echo "  - Successful retries: {$successfulRetries}\n";
    echo "  - Max retry attempts: {$maxRetries}\n";
    echo '  - Average retry response time: '.round($avgRetryTime, 2)."ms\n";

    if ($totalRetries > 0) {
        $successRate = ($successfulRetries / $totalRetries) * 100;
        echo '  - Retry success rate: '.round($successRate, 2)."%\n";
    }
}

// Find problematic endpoints
echo "\nProblematic Endpoints (frequently retried):\n";
$problematicEndpoints = ApiLog::outbound()
    ->selectRaw('url, MAX(retry_attempt) as max_retries, COUNT(*) as total_attempts')
    ->where('retry_attempt', '>', 0)
    ->groupBy('url')
    ->orderByDesc('max_retries')
    ->take(5)
    ->get();

if ($problematicEndpoints->isEmpty()) {
    echo "No problematic endpoints found.\n";
} else {
    foreach ($problematicEndpoints as $endpoint) {
        echo sprintf(
            "  - %s: %d retries across %d attempts\n",
            $endpoint->url,
            $endpoint->max_retries,
            $endpoint->total_attempts
        );
    }
}

echo "\n=== Best Practices for Retry Handling ===\n";
echo "1. Use exponential backoff to avoid overwhelming services\n";
echo "2. Add jitter to prevent thundering herd problems\n";
echo "3. Set reasonable maximum retry attempts (usually 3-5)\n";
echo "4. Only retry on transient errors (network, 5xx, rate limits)\n";
echo "5. Implement circuit breakers for frequently failing services\n";
echo "6. Log all retry attempts for monitoring and analysis\n";
echo "7. Consider using queues for non-time-critical retries\n";
echo "8. Monitor retry patterns to identify systemic issues\n";
