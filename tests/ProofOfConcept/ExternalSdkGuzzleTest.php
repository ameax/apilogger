<?php

namespace Aranes\ApiLogger\Tests\ProofOfConcept;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Simulated external SDK that we cannot modify
 * This represents a third-party package that uses Guzzle internally
 */
class SimulatedExternalSdk
{
    private Client $client;

    public function __construct(Client $client = null)
    {
        // SDK creates its own Guzzle client if none provided
        $this->client = $client ?? new Client([
            'base_uri' => 'https://httpbin.org/',
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'ExternalSDK/1.0',
            ],
        ]);
    }

    public function getUser(int $userId): array
    {
        $response = $this->client->get("anything/users/{$userId}");
        return json_decode($response->getBody()->getContents(), true);
    }

    public function createPost(array $data): array
    {
        $response = $this->client->post('post', [
            'json' => $data,
            'headers' => [
                'X-SDK-Method' => 'createPost',
            ],
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }

    public function uploadFile(string $filename, string $content): array
    {
        $response = $this->client->post('post', [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => $content,
                    'filename' => $filename,
                ],
            ],
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }
}

it('cannot intercept SDK calls when SDK creates its own Guzzle client', function () {
    $capturedData = [];

    // This is what happens when an SDK creates its own client internally
    $sdk = new SimulatedExternalSdk();

    // We have no way to add middleware to the SDK's internal client
    $result = $sdk->getUser(123);

    // The SDK works, but we captured nothing
    expect($result)->toBeArray();
    expect($result)->toHaveKey('url');
    expect($capturedData)->toBeEmpty(); // We couldn't capture anything!
});

it('CAN intercept SDK calls when we provide a configured Guzzle client', function () {
    $capturedData = [];

    // Create our middleware
    $middleware = function ($handler) use (&$capturedData) {
        return function (RequestInterface $request, array $options) use ($handler, &$capturedData) {
            $startTime = microtime(true);

            $requestId = uniqid('sdk-req-');
            $capturedData[$requestId] = [
                'request' => [
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                    'headers' => $request->getHeaders(),
                    'timestamp' => $startTime,
                ],
            ];

            $promise = $handler($request, $options);

            return $promise->then(
                function (ResponseInterface $response) use (&$capturedData, $requestId, $startTime) {
                    $capturedData[$requestId]['response'] = [
                        'status' => $response->getStatusCode(),
                        'duration_ms' => (microtime(true) - $startTime) * 1000,
                    ];
                    return $response;
                }
            );
        };
    };

    // Create a handler stack with our middleware
    $stack = HandlerStack::create();
    $stack->push($middleware, 'apilogger');

    // Create a Guzzle client with our middleware
    $client = new Client([
        'handler' => $stack,
        'base_uri' => 'https://httpbin.org/',
        'timeout' => 30,
        'headers' => [
            'User-Agent' => 'ExternalSDK/1.0',
        ],
    ]);

    // Inject our configured client into the SDK
    $sdk = new SimulatedExternalSdk($client);

    // Now when the SDK makes requests, our middleware captures them!
    $result = $sdk->getUser(123);

    expect($result)->toBeArray();
    expect($capturedData)->not->toBeEmpty();

    $capturedRequest = array_values($capturedData)[0];
    expect($capturedRequest['request']['method'])->toBe('GET');
    expect($capturedRequest['request']['uri'])->toContain('users/123');
    expect($capturedRequest['response']['status'])->toBe(200);
});

it('can intercept multiple SDK operations with dependency injection', function () {
    $capturedData = [];

    $middleware = function ($handler) use (&$capturedData) {
        return function (RequestInterface $request, array $options) use ($handler, &$capturedData) {
            $startTime = microtime(true);

            // Detect SDK method from headers or URI
            $sdkMethod = $request->getHeaderLine('X-SDK-Method') ?: 'unknown';

            $capturedData[] = [
                'sdk_method' => $sdkMethod,
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'body_size' => $request->getBody()->getSize(),
                'timestamp' => $startTime,
            ];

            $request->getBody()->rewind();

            return $handler($request, $options);
        };
    };

    $stack = HandlerStack::create();
    $stack->push($middleware, 'apilogger');

    $client = new Client([
        'handler' => $stack,
        'base_uri' => 'https://httpbin.org/',
    ]);

    $sdk = new SimulatedExternalSdk($client);

    // Test multiple SDK operations
    $sdk->getUser(456);
    $sdk->createPost(['title' => 'Test', 'body' => 'Content']);
    $sdk->uploadFile('test.txt', 'file contents');

    expect($capturedData)->toHaveCount(3);

    // Verify we captured all three operations
    expect($capturedData[0]['method'])->toBe('GET');
    expect($capturedData[0]['uri'])->toContain('users/456');

    expect($capturedData[1]['method'])->toBe('POST');
    expect($capturedData[1]['sdk_method'])->toBe('createPost');

    expect($capturedData[2]['method'])->toBe('POST');
    expect($capturedData[2]['body_size'])->toBeGreaterThan(0);
});

it('demonstrates Laravel service container approach for SDK client injection', function () {
    $capturedData = [];

    // This simulates what we'd do in a Laravel ServiceProvider
    $createConfiguredClient = function () use (&$capturedData) {
        $middleware = function ($handler) use (&$capturedData) {
            return function (RequestInterface $request, array $options) use ($handler, &$capturedData) {
                // Log outbound request
                $capturedData[] = [
                    'service' => $options['sdk_name'] ?? 'unknown',
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                ];

                return $handler($request, $options);
            };
        };

        $stack = HandlerStack::create();
        $stack->push($middleware, 'apilogger');

        return new Client([
            'handler' => $stack,
            'base_uri' => 'https://httpbin.org/',
            // SDK-specific options can be merged here
        ]);
    };

    // In a real Laravel app, this would be in AppServiceProvider:
    // $this->app->bind(SimulatedExternalSdk::class, function ($app) use ($createConfiguredClient) {
    //     return new SimulatedExternalSdk($createConfiguredClient());
    // });

    $sdk = new SimulatedExternalSdk($createConfiguredClient());

    $sdk->getUser(789);

    expect($capturedData)->toHaveCount(1);
    expect($capturedData[0]['method'])->toBe('GET');
    expect($capturedData[0]['uri'])->toContain('users/789');
});

it('shows limitations when SDK does not accept client injection', function () {
    // Some SDKs don't allow client injection at all
    class SealedExternalSdk
    {
        private Client $client;

        public function __construct()
        {
            // No way to inject a client - it's created internally
            $this->client = new Client([
                'base_uri' => 'https://httpbin.org/',
            ]);
        }

        public function makeRequest(): array
        {
            $response = $this->client->get('anything');
            return json_decode($response->getBody()->getContents(), true);
        }
    }

    $capturedData = [];

    // We cannot inject our middleware into this SDK
    $sdk = new SealedExternalSdk();
    $result = $sdk->makeRequest();

    expect($result)->toBeArray();
    expect($capturedData)->toBeEmpty(); // No way to capture!

    // For these cases, we'd need:
    // 1. Fork the SDK and modify it
    // 2. Use a proxy server
    // 3. Use PHP stream wrapper interception (complex and not recommended)
    // 4. Ask the SDK maintainer to add client injection support
});

it('can use global handler to intercept ALL Guzzle clients if set early enough', function () {
    $capturedData = [];

    // This approach sets a default handler for ALL new Guzzle clients
    // Must be done before any SDKs are instantiated
    $globalMiddleware = function ($handler) use (&$capturedData) {
        return function (RequestInterface $request, array $options) use ($handler, &$capturedData) {
            $capturedData[] = [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ];

            return $handler($request, $options);
        };
    };

    // Create a custom default handler
    $stack = HandlerStack::create(new CurlHandler());
    $stack->push($globalMiddleware, 'global_apilogger');

    // This would need to be done very early in the application bootstrap
    // Note: This is a demonstration - Guzzle doesn't have a true global default,
    // but some frameworks allow you to override the default client factory

    // For Laravel, you might do this in a service provider:
    // $this->app->bind(Client::class, function () use ($stack) {
    //     return new Client(['handler' => $stack]);
    // });

    $client = new Client([
        'handler' => $stack,
        'base_uri' => 'https://httpbin.org/',
    ]);
    $sdk = new SimulatedExternalSdk($client);

    $sdk->getUser(999);

    expect($capturedData)->toHaveCount(1);
    expect($capturedData[0]['uri'])->toContain('users/999');
});