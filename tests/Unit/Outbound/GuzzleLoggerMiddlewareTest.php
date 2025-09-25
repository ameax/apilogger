<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Unit\Outbound;

use Ameax\ApiLogger\Contracts\OutboundLoggerInterface;
use Ameax\ApiLogger\Outbound\GuzzleLoggerMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

beforeEach(function () {
    $this->logger = Mockery::mock(OutboundLoggerInterface::class);
    $this->middleware = new GuzzleLoggerMiddleware($this->logger);
});

afterEach(function () {
    Mockery::close();
});

it('logs successful requests', function () {
    $requestId = 'test-request-id';
    $request = new Request('GET', 'https://api.example.com/test');
    $response = new Response(200, ['Content-Type' => 'application/json'], '{"status":"success"}');

    $this->logger->shouldReceive('shouldLog')
        ->once()
        ->with(Mockery::type(RequestInterface::class), Mockery::type('array'))
        ->andReturn(true);

    $this->logger->shouldReceive('logRequest')
        ->once()
        ->with(Mockery::type(RequestInterface::class), Mockery::type('array'))
        ->andReturn($requestId);

    $this->logger->shouldReceive('logResponse')
        ->once()
        ->withArgs(function ($id, $req, $res, $duration, $opts) use ($requestId) {
            return $id === $requestId
                && $req instanceof RequestInterface
                && $res instanceof ResponseInterface
                && is_float($duration)
                && is_array($opts);
        });

    $mock = new MockHandler([$response]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($this->middleware);

    $client = new Client(['handler' => $handlerStack]);
    $result = $client->get('https://api.example.com/test');

    expect($result->getStatusCode())->toBe(200);
    expect((string) $result->getBody())->toBe('{"status":"success"}');
});

it('logs requests with custom options', function () {
    $requestId = 'test-request-id';
    $options = [
        'service_name' => 'TestService',
        'correlation_id' => 'correlation-123',
        'timeout' => 30,
    ];

    $request = new Request('POST', 'https://api.example.com/test');
    $response = new Response(201, [], '{"created":true}');

    $this->logger->shouldReceive('shouldLog')
        ->once()
        ->with(Mockery::type(RequestInterface::class), Mockery::on(function ($opts) {
            return isset($opts['service_name']) && $opts['service_name'] === 'TestService'
                && isset($opts['correlation_id']) && $opts['correlation_id'] === 'correlation-123'
                && isset($opts['timeout']) && $opts['timeout'] === 30;
        }))
        ->andReturn(true);

    $this->logger->shouldReceive('logRequest')
        ->once()
        ->with(Mockery::type(RequestInterface::class), Mockery::on(function ($opts) {
            return isset($opts['service_name']) && $opts['service_name'] === 'TestService'
                && isset($opts['correlation_id']) && $opts['correlation_id'] === 'correlation-123'
                && isset($opts['timeout']) && $opts['timeout'] === 30;
        }))
        ->andReturn($requestId);

    $this->logger->shouldReceive('logResponse')
        ->once()
        ->withArgs(function ($id, $req, $res, $duration, $opts) use ($requestId) {
            return $id === $requestId
                && $req instanceof RequestInterface
                && $res instanceof ResponseInterface
                && is_float($duration)
                && is_array($opts)
                && isset($opts['service_name']) && $opts['service_name'] === 'TestService'
                && isset($opts['correlation_id']) && $opts['correlation_id'] === 'correlation-123'
                && isset($opts['timeout']) && $opts['timeout'] === 30;
        });

    $mock = new MockHandler([$response]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($this->middleware);

    $client = new Client(['handler' => $handlerStack]);
    $result = $client->post('https://api.example.com/test', $options);

    expect($result->getStatusCode())->toBe(201);
});

it('logs error responses', function () {
    $requestId = 'test-request-id';
    $request = new Request('GET', 'https://api.example.com/test');
    $response = new Response(500, [], '{"error":"Internal Server Error"}');

    $this->logger->shouldReceive('shouldLog')
        ->once()
        ->with(Mockery::type(RequestInterface::class), Mockery::type('array'))
        ->andReturn(true);

    $this->logger->shouldReceive('logRequest')
        ->once()
        ->with(Mockery::type(RequestInterface::class), Mockery::type('array'))
        ->andReturn($requestId);

    $this->logger->shouldReceive('logResponse')
        ->once()
        ->withArgs(function ($id, $req, $res, $duration, $opts) use ($requestId) {
            return $id === $requestId
                && $req instanceof RequestInterface
                && $res instanceof ResponseInterface
                && is_float($duration)
                && is_array($opts);
        });

    $mock = new MockHandler([$response]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($this->middleware);

    $client = new Client(['handler' => $handlerStack, 'http_errors' => false]);
    $result = $client->get('https://api.example.com/test');

    expect($result->getStatusCode())->toBe(500);
});

it('logs exceptions', function () {
    $requestId = 'test-request-id';
    $request = new Request('GET', 'https://api.example.com/test');
    $exception = new RequestException(
        'Connection timeout',
        $request,
        null
    );

    $this->logger->shouldReceive('shouldLog')
        ->once()
        ->with(Mockery::type(RequestInterface::class), Mockery::type('array'))
        ->andReturn(true);

    $this->logger->shouldReceive('logRequest')
        ->once()
        ->with(Mockery::type(RequestInterface::class), Mockery::type('array'))
        ->andReturn($requestId);

    $this->logger->shouldReceive('logResponse')
        ->once()
        ->with(
            $requestId,
            Mockery::type(RequestInterface::class),
            null,
            Mockery::type('float'),
            Mockery::type('array'),
            Mockery::type(\Throwable::class)
        );

    $mock = new MockHandler([$exception]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($this->middleware);

    $client = new Client(['handler' => $handlerStack]);

    expect(fn () => $client->get('https://api.example.com/test'))
        ->toThrow(RequestException::class);
});

it('skips logging when shouldLog returns false', function () {
    $request = new Request('GET', 'https://api.example.com/test');
    $response = new Response(200, [], 'OK');

    $this->logger->shouldReceive('shouldLog')
        ->once()
        ->with(Mockery::type(RequestInterface::class), Mockery::type('array'))
        ->andReturn(false);

    $this->logger->shouldNotReceive('logRequest');
    $this->logger->shouldNotReceive('logResponse');

    $mock = new MockHandler([$response]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($this->middleware);

    $client = new Client(['handler' => $handlerStack]);
    $result = $client->get('https://api.example.com/test');

    expect($result->getStatusCode())->toBe(200);
});

it('preserves response body after logging', function () {
    $requestId = 'test-request-id';
    $responseBody = '{"data":"test content"}';
    $request = new Request('GET', 'https://api.example.com/test');
    $response = new Response(200, [], $responseBody);

    $this->logger->shouldReceive('shouldLog')->andReturn(true);
    $this->logger->shouldReceive('logRequest')->andReturn($requestId);
    $this->logger->shouldReceive('logResponse');

    $mock = new MockHandler([$response]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($this->middleware);

    $client = new Client(['handler' => $handlerStack]);
    $result = $client->get('https://api.example.com/test');

    expect((string) $result->getBody())->toBe($responseBody);
    $result->getBody()->rewind();
    expect((string) $result->getBody())->toBe($responseBody);
});

it('can be created using static factory', function () {
    $logger = Mockery::mock(OutboundLoggerInterface::class);
    $middleware = GuzzleLoggerMiddleware::create($logger);

    expect($middleware)->toBeInstanceOf(GuzzleLoggerMiddleware::class);
});

it('handles exceptions with response', function () {
    $requestId = 'test-request-id';
    $request = new Request('GET', 'https://api.example.com/test');
    $response = new Response(404, [], '{"error":"Not Found"}');
    $exception = new RequestException(
        'Not Found',
        $request,
        $response
    );

    $this->logger->shouldReceive('shouldLog')
        ->once()
        ->andReturn(true);

    $this->logger->shouldReceive('logRequest')
        ->once()
        ->andReturn($requestId);

    $this->logger->shouldReceive('logResponse')
        ->once()
        ->with(
            $requestId,
            Mockery::type(RequestInterface::class),
            Mockery::type(ResponseInterface::class),
            Mockery::type('float'),
            Mockery::type('array'),
            Mockery::type(\Throwable::class)
        );

    $mock = new MockHandler([$exception]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($this->middleware);

    $client = new Client(['handler' => $handlerStack]);

    expect(fn () => $client->get('https://api.example.com/test'))
        ->toThrow(RequestException::class);
});

it('measures request duration accurately', function () {
    $requestId = 'test-request-id';
    $capturedDuration = null;

    $this->logger->shouldReceive('shouldLog')->andReturn(true);
    $this->logger->shouldReceive('logRequest')->andReturn($requestId);

    $this->logger->shouldReceive('logResponse')
        ->once()
        ->withArgs(function ($id, $req, $res, $duration) use (&$capturedDuration) {
            $capturedDuration = $duration;

            return true;
        });

    $response = new Response(200, [], 'OK');
    $mock = new MockHandler([$response]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($this->middleware);

    $client = new Client(['handler' => $handlerStack]);
    $client->get('https://api.example.com/test');

    expect($capturedDuration)->toBeGreaterThan(0);
    expect($capturedDuration)->toBeLessThan(1000);
});
