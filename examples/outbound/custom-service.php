<?php

/**
 * Custom Service Configuration Example
 *
 * This example demonstrates how to register and configure a custom service
 * with specific logging requirements and filters.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;
use Ameax\ApiLogger\Outbound\ServiceRegistry;
use GuzzleHttp\Client;

// Register a custom service with specific configuration
ServiceRegistry::register('PaymentGateway', [
    'enabled' => true,
    'name' => 'Stripe Payment API',
    'log_level' => 'full', // Log complete request/response for payment auditing
    'hosts' => [
        'api.stripe.com',
        'files.stripe.com',
        'connect.stripe.com',
    ],
    'always_log_errors' => true, // Always log payment errors regardless of filters
    'slow_threshold_ms' => 2000, // Flag requests taking longer than 2 seconds
    'filters' => [
        // Don't log webhook status checks
        'exclude_endpoints' => [
            '/v1/webhook_endpoints/*/test',
        ],
        // Only log important payment operations
        'include_endpoints' => [
            '/v1/payment_intents*',
            '/v1/customers*',
            '/v1/charges*',
            '/v1/refunds*',
        ],
    ],
    'sanitize_fields' => [
        'card_number',
        'cvv',
        'client_secret',
    ],
]);

// Create a client using the service configuration
$stack = GuzzleHandlerStackFactory::createForService('PaymentGateway');
$paymentClient = new Client([
    'handler' => $stack,
    'base_uri' => 'https://api.stripe.com',
    'headers' => [
        'Authorization' => 'Bearer sk_test_your_stripe_key_here',
    ],
]);

// Register another service with different settings
ServiceRegistry::register('WeatherService', [
    'enabled' => true,
    'name' => 'OpenWeather API',
    'log_level' => 'basic', // Only basic logging for weather API
    'hosts' => ['api.openweathermap.org'],
    'filters' => [
        'min_response_time' => 500, // Only log slow requests (>500ms)
    ],
    'cache_ttl' => 3600, // Weather data can be cached for 1 hour
]);

$weatherStack = GuzzleHandlerStackFactory::createForService('WeatherService');
$weatherClient = new Client([
    'handler' => $weatherStack,
    'base_uri' => 'https://api.openweathermap.org/data/2.5/',
]);

// Example: High-security internal API with no logging
ServiceRegistry::register('InternalSecureAPI', [
    'enabled' => false, // Completely disable logging for security
    'name' => 'Internal Secure Service',
]);

$secureStack = GuzzleHandlerStackFactory::createForService('InternalSecureAPI');
$secureClient = new Client([
    'handler' => $secureStack,
    'base_uri' => 'https://internal-api.example.com',
]);

// Demonstrate usage with different services
echo "=== Payment Service (Full Logging) ===\n";
try {
    // This request will be logged with full details
    $response = $paymentClient->post('/v1/payment_intents', [
        'form_params' => [
            'amount' => 2000,
            'currency' => 'usd',
            'payment_method_types' => ['card'],
        ],
    ]);
    echo "Payment intent created (fully logged)\n";
} catch (\Exception $e) {
    echo 'Payment error (logged with full details): '.$e->getMessage()."\n";
}

echo "\n=== Weather Service (Basic Logging) ===\n";
try {
    // This request will only be logged if it's slow (>500ms)
    $response = $weatherClient->get('weather', [
        'query' => [
            'q' => 'London,uk',
            'appid' => 'your_api_key_here',
        ],
    ]);
    $weather = json_decode($response->getBody(), true);
    echo "Weather in London: {$weather['weather'][0]['description']}\n";
    echo "(Only logged if response time > 500ms)\n";
} catch (\Exception $e) {
    echo 'Weather API error: '.$e->getMessage()."\n";
}

echo "\n=== Internal Secure API (No Logging) ===\n";
try {
    // This request will NOT be logged at all
    $response = $secureClient->get('/api/sensitive-data');
    echo "Sensitive data retrieved (not logged)\n";
} catch (\Exception $e) {
    echo 'Secure API error (not logged): '.$e->getMessage()."\n";
}

// Show how to update service configuration dynamically
echo "\n=== Dynamic Configuration Update ===\n";
ServiceRegistry::update('WeatherService', [
    'log_level' => 'detailed', // Upgrade logging level
    'filters' => [
        'min_response_time' => 0, // Log all requests now
    ],
]);
echo "Weather service logging upgraded to 'detailed' level\n";

// Query logs to see the difference
use Ameax\ApiLogger\Models\ApiLog;

echo "\n=== Logged Requests by Service ===\n";
$services = ['PaymentGateway', 'WeatherService', 'InternalSecureAPI'];

foreach ($services as $serviceName) {
    $count = ApiLog::outbound()
        ->forService($serviceName)
        ->whereDate('created_at', today())
        ->count();

    echo "- {$serviceName}: {$count} requests logged today\n";
}
