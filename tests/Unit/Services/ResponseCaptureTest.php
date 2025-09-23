<?php

declare(strict_types=1);

use Ameax\ApiLogger\Services\ResponseCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->config = [
        'level' => 'full',
        'performance' => [
            'max_body_size' => 1024,
        ],
        'enrichment' => [
            'capture_memory' => false,
        ],
    ];

    $this->responseCapture = new ResponseCapture($this->config);
    $this->startTime = microtime(true);
});

it('captures basic response information', function () {
    $response = new Response('Hello World', 200);
    $response->headers->set('Content-Type', 'text/plain');

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured)->toHaveKeys(['status_code', 'headers', 'body', 'response_time_ms']);
    expect($captured['status_code'])->toBe(200);
    expect($captured['body'])->toBe('Hello World');
    expect($captured['headers'])->toHaveKey('content-type');
    expect($captured['response_time_ms'])->toBeFloat();
});

it('captures JSON response data', function () {
    $data = ['success' => true, 'message' => 'Operation completed'];
    $response = new JsonResponse($data, 201);

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['status_code'])->toBe(201);
    expect($captured['body'])->toBe($data);
});

it('handles large JSON responses', function () {
    $largeData = [];
    for ($i = 0; $i < 100; $i++) {
        $largeData[] = [
            'id' => $i,
            'data' => str_repeat('x', 50),
        ];
    }

    $response = new JsonResponse($largeData);

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['body'])->toBeArray();
    expect($captured['body']['_truncated'])->toBeTrue();
    expect($captured['body']['original_size'])->toBeGreaterThan(1024);
});

it('handles streamed responses', function () {
    $response = new StreamedResponse(function () {
        echo 'Streaming content';
    });

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['body'])->toBeArray();
    expect($captured['body']['_type'])->toBe('streamed');
    expect($captured['body']['note'])->toBe('Content not captured for streamed responses');
});

it('handles binary file responses', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'test');
    file_put_contents($tempFile, 'binary content');

    $response = new BinaryFileResponse($tempFile);

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['body'])->toBeArray();
    expect($captured['body']['_type'])->toBe('binary_file');
    expect($captured['body'])->toHaveKeys(['filename', 'path', 'size', 'mime_type']);

    unlink($tempFile);
});

it('truncates large text responses', function () {
    $largeContent = str_repeat('Lorem ipsum ', 200);
    $response = new Response($largeContent);

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['body'])->toBeArray();
    expect($captured['body']['_truncated'])->toBeTrue();
    expect($captured['body']['original_size'])->toBeGreaterThan(1024);
    expect(strlen($captured['body']['content']))->toBe(1024);
});

it('does not capture body when level is not full', function () {
    $config = array_merge($this->config, ['level' => 'basic']);
    $responseCapture = new ResponseCapture($config);

    $response = new Response('Body content', 200);

    $captured = $responseCapture->capture($response, $this->startTime);

    expect($captured['body'])->toBeNull();
});

it('captures memory usage when configured', function () {
    $config = array_merge($this->config, ['enrichment' => ['capture_memory' => true]]);
    $responseCapture = new ResponseCapture($config);

    $response = new Response('OK', 200);

    $captured = $responseCapture->capture($response, $this->startTime);

    expect($captured)->toHaveKey('memory_usage');
    expect($captured['memory_usage'])->toBeInt();
    expect($captured['memory_usage'])->toBeGreaterThan(0);
});

it('calculates response time accurately', function () {
    $response = new Response('OK', 200);

    // Add a small delay
    usleep(50000); // 50ms

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['response_time_ms'])->toBeGreaterThan(50);
    expect($captured['response_time_ms'])->toBeLessThan(100);
});

it('handles JSON response with invalid JSON in content', function () {
    $response = new Response('{"invalid": json}', 200);
    $response->headers->set('Content-Type', 'application/json');

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['body'])->toBe('{"invalid": json}');
});

it('handles empty responses', function () {
    $response = new Response('', 204);

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['status_code'])->toBe(204);
    expect($captured['body'])->toBe('');
});

it('captures all response headers', function () {
    $response = new Response('OK', 200);
    $response->headers->set('Content-Type', 'text/plain');
    $response->headers->set('X-Custom-Header', 'custom-value');
    $response->headers->set('Cache-Control', 'no-cache');

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['headers'])->toHaveKey('content-type');
    expect($captured['headers'])->toHaveKey('x-custom-header');
    expect($captured['headers'])->toHaveKey('cache-control');
    expect($captured['headers']['x-custom-header'])->toBe('custom-value');
});

it('truncates nested JSON objects correctly', function () {
    $data = [
        'users' => [],
        'meta' => ['total' => 1000],
    ];

    for ($i = 0; $i < 50; $i++) {
        $data['users'][] = [
            'id' => $i,
            'name' => 'User '.$i,
            'email' => 'user'.$i.'@example.com',
        ];
    }

    $response = new JsonResponse($data);

    $captured = $this->responseCapture->capture($response, $this->startTime);

    expect($captured['body'])->toBeArray();
    expect($captured['body']['_truncated'])->toBeTrue();
    expect($captured['body']['preview'])->toHaveKey('users');
    expect($captured['body']['preview'])->toHaveKey('_truncated_at');
});

describe('shouldCaptureBody', function () {
    it('returns false when level is not full', function () {
        $config = ['level' => 'basic'];
        $responseCapture = new ResponseCapture($config);
        $response = new Response('OK', 200);

        expect($responseCapture->shouldCaptureBody($response))->toBeFalse();
    });

    it('returns true for error responses regardless of content type', function () {
        $response400 = new Response('Bad Request', 400);
        $response500 = new Response('Server Error', 500);

        expect($this->responseCapture->shouldCaptureBody($response400))->toBeTrue();
        expect($this->responseCapture->shouldCaptureBody($response500))->toBeTrue();
    });

    it('returns false for binary content types', function () {
        $response = new Response('binary', 200);
        $response->headers->set('Content-Type', 'application/pdf');

        expect($this->responseCapture->shouldCaptureBody($response))->toBeFalse();
    });

    it('returns true for text content types', function () {
        $jsonResponse = new Response('{}', 200);
        $jsonResponse->headers->set('Content-Type', 'application/json');

        $htmlResponse = new Response('<html></html>', 200);
        $htmlResponse->headers->set('Content-Type', 'text/html');

        expect($this->responseCapture->shouldCaptureBody($jsonResponse))->toBeTrue();
        expect($this->responseCapture->shouldCaptureBody($htmlResponse))->toBeTrue();
    });
});
