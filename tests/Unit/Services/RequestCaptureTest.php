<?php

declare(strict_types=1);

use Ameax\ApiLogger\Services\RequestCapture;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->config = [
        'level' => 'full',
        'performance' => [
            'max_body_size' => 1024,
        ],
        'enrichment' => [
            'capture_ip' => true,
            'capture_user' => true,
            'capture_user_agent' => true,
            'user_identifier' => 'id',
        ],
    ];

    $this->requestCapture = new RequestCapture($this->config);
});

it('captures basic request information', function () {
    $request = Request::create('/api/users', 'POST', ['name' => 'John']);
    $request->headers->set('User-Agent', 'TestAgent/1.0');

    $captured = $this->requestCapture->capture($request);

    expect($captured)->toHaveKeys(['method', 'endpoint', 'headers', 'body', 'query_params', 'ip_address', 'user_agent', 'user_identifier', 'correlation_identifier']);
    expect($captured['method'])->toBe('POST');
    expect($captured['endpoint'])->toBe('/api/users');
    expect($captured['body'])->toBe(['name' => 'John']);
    expect($captured['user_agent'])->toBe('TestAgent/1.0');
    expect($captured['correlation_identifier'])->toBeString();
});

it('normalizes endpoint paths', function () {
    $request1 = Request::create('api/users', 'GET');
    $request2 = Request::create('/api/users', 'GET');

    $captured1 = $this->requestCapture->capture($request1);
    $captured2 = $this->requestCapture->capture($request2);

    expect($captured1['endpoint'])->toBe('/api/users');
    expect($captured2['endpoint'])->toBe('/api/users');
});

it('captures headers correctly', function () {
    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('X-Custom-Header', 'custom-value');

    $captured = $this->requestCapture->capture($request);

    expect($captured['headers'])->toHaveKey('content-type');
    expect($captured['headers'])->toHaveKey('accept');
    expect($captured['headers'])->toHaveKey('x-custom-header');
    expect($captured['headers']['x-custom-header'])->toBe('custom-value');
});

it('handles JSON body content', function () {
    $jsonData = ['user' => 'john', 'action' => 'login'];
    $request = Request::create('/api/login', 'POST', [], [], [], [], json_encode($jsonData));
    $request->headers->set('Content-Type', 'application/json');

    $captured = $this->requestCapture->capture($request);

    expect($captured['body'])->toBe($jsonData);
});

it('handles form data content', function () {
    $request = Request::create('/api/submit', 'POST', ['field1' => 'value1', 'field2' => 'value2']);
    $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

    $captured = $this->requestCapture->capture($request);

    expect($captured['body'])->toBe(['field1' => 'value1', 'field2' => 'value2']);
});

it('handles file uploads', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);

    $request = Request::create('/api/upload', 'POST');
    $request->files->set('image', $file);
    $request->merge(['title' => 'Test Upload']);

    $captured = $this->requestCapture->capture($request);

    expect($captured['body'])->toHaveKey('_files');
    expect($captured['body']['_files']['image'])->toHaveKeys(['name', 'mime_type', 'size', 'extension']);
    expect($captured['body']['_files']['image']['name'])->toBe('test.jpg');
    expect($captured['body']['title'])->toBe('Test Upload');
});

it('handles binary content', function () {
    $request = Request::create('/api/upload', 'POST');
    $request->headers->set('Content-Type', 'application/octet-stream');
    $request->headers->set('Content-Length', '1024');

    $captured = $this->requestCapture->capture($request);

    expect($captured['body'])->toBeArray();
    expect($captured['body']['_binary'])->toBeTrue();
    expect($captured['body']['content_type'])->toBe('application/octet-stream');
    expect($captured['body']['size'])->toBe(1024);
});

it('truncates large bodies', function () {
    $largeData = str_repeat('x', 2000);
    $request = Request::create('/api/test', 'POST', [], [], [], [], $largeData);

    $captured = $this->requestCapture->capture($request);

    expect($captured['body'])->toBeArray();
    expect($captured['body']['_truncated'])->toBeTrue();
    expect($captured['body']['original_size'])->toBe(2000);
    expect(strlen($captured['body']['content']))->toBe(1024);
});

it('does not capture body when level is not full', function () {
    $config = array_merge($this->config, ['level' => 'basic']);
    $requestCapture = new RequestCapture($config);

    $request = Request::create('/api/test', 'POST', ['data' => 'test']);

    $captured = $requestCapture->capture($request);

    expect($captured['body'])->toBeNull();
});

it('captures IP address correctly', function () {
    $request = Request::create('/api/test', 'GET');
    $request->server->set('REMOTE_ADDR', '192.168.1.1');

    $captured = $this->requestCapture->capture($request);

    expect($captured['ip_address'])->toBe('192.168.1.1');
});

it('handles proxy headers for IP address', function () {
    $request = Request::create('/api/test', 'GET');
    $request->headers->set('X-Forwarded-For', '203.0.113.1, 198.51.100.2');
    $request->server->set('REMOTE_ADDR', '10.0.0.1');

    $captured = $this->requestCapture->capture($request);

    expect($captured['ip_address'])->toBe('203.0.113.1');
});

it('captures authenticated user identifier', function () {
    $user = new class
    {
        public $id = 123;

        public $email = 'user@example.com';

        public function getKey()
        {
            return $this->id;
        }
    };

    Auth::shouldReceive('user')->andReturn($user);

    $request = Request::create('/api/test', 'GET');
    $request->setUserResolver(fn () => $user);

    $captured = $this->requestCapture->capture($request);

    expect($captured['user_identifier'])->toBe('123');
});

it('handles different user identifier fields', function () {
    $config = array_merge($this->config, ['enrichment' => ['capture_user' => true, 'user_identifier' => 'email']]);
    $requestCapture = new RequestCapture($config);

    $user = new class
    {
        public $id = 123;

        public $email = 'user@example.com';

        public function getKey()
        {
            return $this->id;
        }
    };

    $request = Request::create('/api/test', 'GET');
    $request->setUserResolver(fn () => $user);

    $captured = $requestCapture->capture($request);

    expect($captured['user_identifier'])->toBe('user@example.com');
});

it('uses existing correlation ID from headers', function () {
    $request = Request::create('/api/test', 'GET');
    $request->headers->set('X-Correlation-ID', 'existing-correlation-id');

    $captured = $this->requestCapture->capture($request);

    expect($captured['correlation_identifier'])->toBe('existing-correlation-id');
});

it('generates new request ID when no correlation ID exists', function () {
    $request = Request::create('/api/test', 'GET');

    $captured = $this->requestCapture->capture($request);

    expect($captured['correlation_identifier'])->toBeString();
    expect($captured['correlation_identifier'])->toMatch('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/');
});

it('calls custom enrichment callback', function () {
    $config = array_merge($this->config, [
        'enrichment' => [
            'custom_callback' => function ($request) {
                return [
                    'custom_field' => 'custom_value',
                    'path_length' => strlen($request->path()),
                ];
            },
        ],
    ]);

    $requestCapture = new RequestCapture($config);

    $request = Request::create('/api/test', 'GET');

    $captured = $requestCapture->capture($request);

    expect($captured)->toHaveKey('metadata');
    expect($captured['metadata'])->toHaveKey('custom_field');
    expect($captured['metadata']['custom_field'])->toBe('custom_value');
    expect($captured['metadata'])->toHaveKey('path_length');
    expect($captured['metadata']['path_length'])->toBe(8);
    expect($captured['metadata'])->toHaveKey('correlation_id');
    expect($captured['metadata'])->toHaveKey('direction');
    expect($captured['metadata']['direction'])->toBe('inbound');
});
