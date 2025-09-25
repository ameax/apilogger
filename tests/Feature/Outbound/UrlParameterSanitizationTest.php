<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Feature\Outbound;

use Ameax\ApiLogger\Models\ApiLog;
use Ameax\ApiLogger\Outbound\GuzzleLoggerMiddleware;
use Ameax\ApiLogger\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class UrlParameterSanitizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'apilogger.features.outbound.enabled' => true,
            'apilogger.storage.driver' => 'database',
            'apilogger.performance.use_queue' => false,
        ]);

        $this->artisan('migrate');
    }

    public function test_it_sanitizes_tokens_in_outbound_request_urls(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['status' => 'success'])),
        ]);

        $handlerStack = HandlerStack::create($mock);

        // Add the logging middleware
        $middleware = app(GuzzleLoggerMiddleware::class);
        $handlerStack->push($middleware);

        $client = new Client(['handler' => $handlerStack]);

        // Make request with sensitive params
        $response = $client->get('https://api.example.com/endpoint', [
            'query' => [
                'api_key' => 'sk_live_secret123',
                'token' => 'bearer_xyz',
                'format' => 'json',
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());

        // Check the logged data
        $log = ApiLog::first();
        $this->assertNotNull($log);

        // The endpoint should not contain query params
        $this->assertEquals('https://api.example.com/endpoint', $log->endpoint);

        // Check metadata for sanitized query params
        $metadata = $log->metadata;
        $this->assertArrayHasKey('query_params', $metadata);
        $this->assertEquals('[REDACTED]', $metadata['query_params']['api_key']);
        $this->assertEquals('[REDACTED]', $metadata['query_params']['token']);
        $this->assertEquals('json', $metadata['query_params']['format']);
    }

    public function test_it_sanitizes_url_with_mixed_params(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['data' => 'test'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $middleware = app(GuzzleLoggerMiddleware::class);
        $handlerStack->push($middleware);

        $client = new Client(['handler' => $handlerStack]);

        // Make request with URL that has query string
        $response = $client->post('https://webhook.site/unique-id?secret=webhook_secret&event=user.created', [
            'json' => ['user_id' => 123],
        ]);

        $this->assertEquals(200, $response->getStatusCode());

        $log = ApiLog::first();
        $this->assertNotNull($log);

        // Endpoint should be clean
        $this->assertEquals('https://webhook.site/unique-id', $log->endpoint);

        // Query params should be sanitized in metadata
        $metadata = $log->metadata;
        $this->assertArrayHasKey('query_params', $metadata);
        $this->assertEquals('[REDACTED]', $metadata['query_params']['secret']);
        $this->assertEquals('user.created', $metadata['query_params']['event']);
    }

    public function test_it_handles_urls_without_query_params(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $middleware = app(GuzzleLoggerMiddleware::class);
        $handlerStack->push($middleware);

        $client = new Client(['handler' => $handlerStack]);

        $response = $client->get('https://api.example.com/users/123');

        $this->assertEquals(200, $response->getStatusCode());

        $log = ApiLog::first();
        $this->assertNotNull($log);

        $this->assertEquals('https://api.example.com/users/123', $log->endpoint);

        // Should not have query_params in metadata if there are none
        $metadata = $log->metadata;
        if (isset($metadata['query_params'])) {
            $this->assertEmpty($metadata['query_params']);
        }
    }
}
