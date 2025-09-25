<?php

/**
 * Basic Guzzle Client with API Logging
 *
 * This example shows the simplest way to add logging to a Guzzle client.
 * All requests made through this client will be automatically logged.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Ameax\ApiLogger\Outbound\GuzzleHandlerStackFactory;
use GuzzleHttp\Client;

// Create a Guzzle client with automatic logging
$stack = GuzzleHandlerStackFactory::create();
$client = new Client([
    'handler' => $stack,
    'base_uri' => 'https://jsonplaceholder.typicode.com',
    'timeout' => 10,
]);

try {
    // Make a simple GET request - this will be logged automatically
    echo "Fetching user data...\n";
    $response = $client->get('/users/1');

    $userData = json_decode($response->getBody(), true);
    echo "User: {$userData['name']} ({$userData['email']})\n\n";

    // Make a POST request - also logged automatically
    echo "Creating a new post...\n";
    $response = $client->post('/posts', [
        'json' => [
            'userId' => 1,
            'title' => 'Test Post',
            'body' => 'This is a test post created with logged Guzzle client.',
        ],
    ]);

    $postData = json_decode($response->getBody(), true);
    echo "Created post with ID: {$postData['id']}\n\n";

    // Make multiple requests to see them all logged
    echo "Fetching multiple posts...\n";
    $postIds = [1, 2, 3];
    foreach ($postIds as $postId) {
        $response = $client->get("/posts/{$postId}");
        $post = json_decode($response->getBody(), true);
        echo "- Post {$postId}: {$post['title']}\n";
    }

    echo "\nAll requests have been logged to the database!\n";
    echo "Check your api_logs table to see the logged requests.\n";

} catch (\GuzzleHttp\Exception\GuzzleException $e) {
    echo 'Error: '.$e->getMessage()."\n";
    echo "This error has also been logged.\n";
}

// Query the logs to see what was captured
use Ameax\ApiLogger\Models\ApiLog;

echo "\n--- Recent Outbound Logs ---\n";
$logs = ApiLog::outbound()
    ->latest()
    ->take(5)
    ->get();

foreach ($logs as $log) {
    echo sprintf(
        "%s %s -> %d in %dms\n",
        $log->method,
        $log->url,
        $log->response_code,
        $log->response_time
    );
}
