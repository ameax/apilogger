<?php

/**
 * Correlation ID Example
 *
 * This example shows how to use correlation IDs to track related requests
 * across multiple services and API calls.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Ameax\ApiLogger\Models\ApiLog;
use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;
use Ameax\ApiLogger\Support\CorrelationIdManager;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

// Get or create a correlation ID
$correlationManager = app(CorrelationIdManager::class);

// Simulate an incoming request with a correlation ID
$incomingCorrelationId = Str::uuid()->toString();
$correlationManager->setCorrelationId($incomingCorrelationId);

echo "=== Request Flow with Correlation ID: {$incomingCorrelationId} ===\n\n";

// Create clients for different services
$userServiceClient = new Client([
    'handler' => GuzzleHandlerStackFactory::create(),
    'base_uri' => 'https://jsonplaceholder.typicode.com',
]);

$postServiceClient = new Client([
    'handler' => GuzzleHandlerStackFactory::create(),
    'base_uri' => 'https://jsonplaceholder.typicode.com',
]);

$notificationClient = new Client([
    'handler' => GuzzleHandlerStackFactory::create(),
    'base_uri' => 'https://jsonplaceholder.typicode.com',
]);

// Simulate a complex operation that involves multiple services
echo "Step 1: Fetch user information\n";
$userResponse = $userServiceClient->get('/users/1', [
    'headers' => [
        'X-Correlation-ID' => $correlationManager->getCorrelationId(),
    ],
]);
$user = json_decode($userResponse->getBody(), true);
echo "  - User: {$user['name']}\n";

echo "\nStep 2: Create a post for the user\n";
$postResponse = $postServiceClient->post('/posts', [
    'headers' => [
        'X-Correlation-ID' => $correlationManager->getCorrelationId(),
    ],
    'json' => [
        'userId' => $user['id'],
        'title' => 'Correlated Post Example',
        'body' => 'This post is part of a correlated request chain.',
    ],
]);
$post = json_decode($postResponse->getBody(), true);
echo "  - Created post ID: {$post['id']}\n";

echo "\nStep 3: Fetch user's other posts\n";
$postsResponse = $postServiceClient->get('/users/1/posts', [
    'headers' => [
        'X-Correlation-ID' => $correlationManager->getCorrelationId(),
    ],
]);
$posts = json_decode($postsResponse->getBody(), true);
echo '  - Found '.count($posts)." posts\n";

echo "\nStep 4: Send notification (simulated)\n";
$notificationResponse = $notificationClient->post('/posts', [
    'headers' => [
        'X-Correlation-ID' => $correlationManager->getCorrelationId(),
    ],
    'json' => [
        'type' => 'notification',
        'userId' => $user['id'],
        'message' => "New post created: {$post['title']}",
    ],
]);
echo "  - Notification sent\n";

// Now trace the entire request chain using the correlation ID
echo "\n=== Tracing Request Chain ===\n";

$requestChain = ApiLog::withCorrelation($incomingCorrelationId)
    ->orderBy('created_at')
    ->get();

if ($requestChain->isEmpty()) {
    echo "No requests found with correlation ID. They may not have been persisted yet.\n";
    echo "In a real application, you might need to wait for queue processing.\n";
} else {
    foreach ($requestChain as $index => $log) {
        $step = $index + 1;
        echo "\n[Step {$step}] {$log->created_at->format('H:i:s.u')}\n";
        echo "  Direction: {$log->direction}\n";
        echo "  Method: {$log->method}\n";
        echo "  URL: {$log->url}\n";
        echo "  Status: {$log->response_code}\n";
        echo "  Time: {$log->response_time}ms\n";

        if ($log->service) {
            echo "  Service: {$log->service}\n";
        }
    }

    // Calculate total time for the entire chain
    $firstRequest = $requestChain->first();
    $lastRequest = $requestChain->last();
    $totalTime = $firstRequest->created_at->diffInMilliseconds($lastRequest->created_at);

    echo "\n=== Chain Statistics ===\n";
    echo 'Total requests: '.$requestChain->count()."\n";
    echo "Total time: {$totalTime}ms\n";
    echo 'Average response time: '.round($requestChain->avg('response_time'), 2)."ms\n";

    $errors = $requestChain->where('response_code', '>=', 400);
    if ($errors->count() > 0) {
        echo 'Errors: '.$errors->count()."\n";
        foreach ($errors as $error) {
            echo "  - {$error->method} {$error->url}: {$error->response_code}\n";
        }
    }
}

// Demonstrate parent-child correlation
echo "\n=== Parent-Child Correlation ===\n";

$parentCorrelationId = Str::uuid()->toString();
$correlationManager->setCorrelationId($parentCorrelationId);
echo "Parent Correlation ID: {$parentCorrelationId}\n";

// Main request
$mainClient = new Client([
    'handler' => GuzzleHandlerStackFactory::create(),
    'base_uri' => 'https://jsonplaceholder.typicode.com',
]);

echo "\nMaking parent request...\n";
$mainResponse = $mainClient->get('/users', [
    'headers' => [
        'X-Correlation-ID' => $parentCorrelationId,
    ],
]);

// Create child correlations for parallel requests
$users = json_decode($mainResponse->getBody(), true);
$userIds = array_slice(array_column($users, 'id'), 0, 3);

echo 'Making child requests for users: '.implode(', ', $userIds)."\n";

foreach ($userIds as $userId) {
    // Each child request maintains the parent correlation
    $childResponse = $mainClient->get("/users/{$userId}/posts", [
        'headers' => [
            'X-Correlation-ID' => $parentCorrelationId,
            'X-Parent-Request-ID' => $parentCorrelationId,
            'X-User-ID' => $userId,
        ],
    ]);
    echo "  - Fetched posts for user {$userId}\n";
}

echo "\n=== Analyzing Correlated Requests ===\n";

// Helper function to visualize request hierarchy
function displayRequestHierarchy($correlationId)
{
    $requests = ApiLog::withCorrelation($correlationId)
        ->orderBy('created_at')
        ->get();

    if ($requests->isEmpty()) {
        echo "No requests found for correlation ID: {$correlationId}\n";

        return;
    }

    $tree = [];
    foreach ($requests as $request) {
        $indent = str_contains($request->url, '/posts') ? '  └─ ' : '';
        echo "{$indent}{$request->method} {$request->url} [{$request->response_time}ms]\n";
    }
}

displayRequestHierarchy($parentCorrelationId);

echo "\n=== Tips for Using Correlation IDs ===\n";
echo "1. Always propagate correlation IDs to external services\n";
echo "2. Use consistent header names (X-Correlation-ID, X-Request-ID)\n";
echo "3. Include correlation IDs in error logs for easier debugging\n";
echo "4. Consider adding parent-child relationships for complex flows\n";
echo "5. Use correlation IDs to calculate end-to-end latency\n";
