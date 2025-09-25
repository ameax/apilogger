<?php

namespace Aranes\ApiLogger\Tests\ProofOfConcept;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('can capture outbound API requests with Guzzle middleware', function () {
    $capturedData = [];

    $middleware = function ($handler) use (&$capturedData) {
        return function (RequestInterface $request, array $options) use ($handler, &$capturedData) {
            $startTime = microtime(true);

            $capturedData['request'] = [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
                'body' => (string) $request->getBody(),
                'timestamp' => $startTime,
            ];

            $request->getBody()->rewind();

            $promise = $handler($request, $options);

            return $promise->then(
                function (ResponseInterface $response) use (&$capturedData, $startTime) {
                    $capturedData['response'] = [
                        'status' => $response->getStatusCode(),
                        'headers' => $response->getHeaders(),
                        'body' => (string) $response->getBody(),
                        'timestamp' => microtime(true),
                    ];

                    $capturedData['duration_ms'] = (microtime(true) - $startTime) * 1000;

                    $response->getBody()->rewind();

                    return $response;
                }
            );
        };
    };

    $stack = HandlerStack::create();
    $stack->push($middleware, 'logger');

    $client = new Client([
        'handler' => $stack,
        'http_errors' => false,
    ]);

    $response = $client->post('https://httpbin.org/post', [
        'json' => [
            'test' => 'data',
            'nested' => [
                'key' => 'value',
            ],
        ],
        'headers' => [
            'X-Custom-Header' => 'test-value',
            'User-Agent' => 'ApiLogger/Test',
        ],
    ]);

    expect($capturedData)->toHaveKey('request');
    expect($capturedData)->toHaveKey('response');
    expect($capturedData)->toHaveKey('duration_ms');

    expect($capturedData['request']['method'])->toBe('POST');
    expect($capturedData['request']['uri'])->toContain('httpbin.org/post');
    expect($capturedData['request']['headers'])->toHaveKey('X-Custom-Header');
    expect($capturedData['request']['body'])->toContain('test');

    expect($capturedData['response']['status'])->toBe(200);
    expect($capturedData['response']['body'])->toContain('test');

    expect($capturedData['duration_ms'])->toBeGreaterThan(0);
});

it('can capture error responses with Guzzle middleware', function () {
    $capturedData = [];
    $capturedError = null;

    $middleware = function ($handler) use (&$capturedData, &$capturedError) {
        return function (RequestInterface $request, array $options) use ($handler, &$capturedData, &$capturedError) {
            $startTime = microtime(true);

            $capturedData['request'] = [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
                'body' => (string) $request->getBody(),
                'timestamp' => $startTime,
            ];

            $request->getBody()->rewind();

            $promise = $handler($request, $options);

            return $promise->then(
                function (ResponseInterface $response) use (&$capturedData, $startTime) {
                    $capturedData['response'] = [
                        'status' => $response->getStatusCode(),
                        'headers' => $response->getHeaders(),
                        'body' => (string) $response->getBody(),
                        'timestamp' => microtime(true),
                    ];

                    $capturedData['duration_ms'] = (microtime(true) - $startTime) * 1000;

                    $response->getBody()->rewind();

                    return $response;
                },
                function ($reason) use (&$capturedData, &$capturedError, $startTime) {
                    $capturedError = [
                        'type' => get_class($reason),
                        'message' => $reason->getMessage(),
                        'timestamp' => microtime(true),
                    ];

                    $capturedData['duration_ms'] = (microtime(true) - $startTime) * 1000;

                    throw $reason;
                }
            );
        };
    };

    $stack = HandlerStack::create();
    $stack->push($middleware, 'logger');

    $client = new Client([
        'handler' => $stack,
        'http_errors' => false,
    ]);

    $response = $client->get('https://httpbin.org/status/500');

    expect($capturedData)->toHaveKey('request');
    expect($capturedData)->toHaveKey('response');
    expect($capturedData['response']['status'])->toBe(500);
});

it('can capture additional metadata from Guzzle options', function () {
    $capturedData = [];

    $middleware = function ($handler) use (&$capturedData) {
        return function (RequestInterface $request, array $options) use ($handler, &$capturedData) {
            $startTime = microtime(true);

            $capturedData['metadata'] = [
                'service_name' => $options['service_name'] ?? null,
                'correlation_id' => $options['correlation_id'] ?? null,
                'timeout' => $options['timeout'] ?? null,
                'verify' => $options['verify'] ?? true,
                'allow_redirects' => $options['allow_redirects'] ?? true,
            ];

            $capturedData['request'] = [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'host' => $request->getUri()->getHost(),
                'path' => $request->getUri()->getPath(),
                'query' => $request->getUri()->getQuery(),
            ];

            $promise = $handler($request, $options);

            return $promise->then(
                function (ResponseInterface $response) use (&$capturedData, $startTime) {
                    $capturedData['timing'] = [
                        'start' => $startTime,
                        'end' => microtime(true),
                        'duration_ms' => (microtime(true) - $startTime) * 1000,
                    ];

                    return $response;
                }
            );
        };
    };

    $stack = HandlerStack::create();
    $stack->push($middleware, 'logger');

    $client = new Client([
        'handler' => $stack,
        'http_errors' => false,
    ]);

    $response = $client->get('https://httpbin.org/get', [
        'query' => ['test' => 'param'],
        'timeout' => 30,
        'service_name' => 'TestService',
        'correlation_id' => 'test-correlation-123',
        'headers' => [
            'Authorization' => 'Bearer secret-token-123',
        ],
    ]);

    expect($capturedData)->toHaveKey('metadata');
    expect($capturedData['metadata']['service_name'])->toBe('TestService');
    expect($capturedData['metadata']['correlation_id'])->toBe('test-correlation-123');
    expect($capturedData['metadata']['timeout'])->toBe(30);

    expect($capturedData['request']['host'])->toBe('httpbin.org');
    expect($capturedData['request']['path'])->toBe('/get');
    expect($capturedData['request']['query'])->toContain('test=param');
});

it('can handle POST requests with different content types', function () {
    $capturedData = [];

    $middleware = Middleware::tap(
        function (RequestInterface $request) use (&$capturedData) {
            $contentType = $request->getHeaderLine('Content-Type');
            $body = (string) $request->getBody();

            $capturedData['request'] = [
                'method' => $request->getMethod(),
                'content_type' => $contentType,
                'body' => $body,
                'body_size' => strlen($body),
            ];

            $request->getBody()->rewind();
        }
    );

    $stack = HandlerStack::create();
    $stack->push($middleware, 'logger');

    $client = new Client([
        'handler' => $stack,
        'http_errors' => false,
    ]);

    $response = $client->post('https://httpbin.org/post', [
        'form_params' => [
            'field1' => 'value1',
            'field2' => 'value2',
        ],
    ]);

    expect($capturedData['request']['method'])->toBe('POST');
    expect($capturedData['request']['content_type'])->toContain('application/x-www-form-urlencoded');
    expect($capturedData['request']['body'])->toContain('field1=value1');

    $capturedData = [];

    $response = $client->post('https://httpbin.org/post', [
        'multipart' => [
            [
                'name' => 'field',
                'contents' => 'value',
            ],
            [
                'name' => 'file',
                'contents' => 'file contents',
                'filename' => 'test.txt',
            ],
        ],
    ]);

    expect($capturedData['request']['content_type'])->toContain('multipart/form-data');
    expect($capturedData['request']['body'])->toContain('Content-Disposition');
});
