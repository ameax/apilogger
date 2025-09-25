<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Feature;

use Ameax\ApiLogger\Middleware\LogApiRequests;
use Ameax\ApiLogger\Models\ApiLog;
use Ameax\ApiLogger\Services\DataSanitizer;
use Ameax\ApiLogger\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class UrlParameterSanitizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'apilogger.enabled' => true,
            'apilogger.level' => 'full',
            'apilogger.storage.driver' => 'database',
            'apilogger.performance.use_queue' => false,
        ]);

        $this->artisan('migrate');
    }

    public function test_it_sanitizes_token_in_query_parameters(): void
    {
        // Create a test route
        Route::middleware(LogApiRequests::class)->get('/api/test', function (Request $request) {
            return response()->json(['message' => 'success']);
        });

        // Make request with token in query params
        $response = $this->get('/api/test?token=secret-token-12345&user=john');

        $response->assertStatus(200);

        // Check the logged data
        $log = ApiLog::first();
        $this->assertNotNull($log);

        // Check that the token is sanitized in request_parameters
        $requestParams = $log->request_parameters;
        $this->assertNotNull($requestParams);
        $this->assertEquals('[REDACTED]', $requestParams['token']);
        $this->assertEquals('john', $requestParams['user']);

        // Ensure the endpoint doesn't contain the query string
        $this->assertEquals('/api/test', $log->endpoint);
    }

    public function test_it_sanitizes_api_key_in_query_parameters(): void
    {
        Route::middleware(LogApiRequests::class)->get('/api/endpoint', function (Request $request) {
            return response()->json(['status' => 'ok']);
        });

        $response = $this->get('/api/endpoint?api_key=sk_live_abcdef123456&format=json');

        $response->assertStatus(200);

        $log = ApiLog::first();
        $this->assertNotNull($log);

        $requestParams = $log->request_parameters;
        $this->assertNotNull($requestParams);
        $this->assertEquals('[REDACTED]', $requestParams['api_key']);
        $this->assertEquals('json', $requestParams['format']);
    }

    public function test_it_sanitizes_multiple_sensitive_params(): void
    {
        Route::middleware(LogApiRequests::class)->post('/api/auth', function (Request $request) {
            return response()->json(['authenticated' => true]);
        });

        $response = $this->post('/api/auth?token=bearer-xyz&secret=mysecret&username=admin');

        $response->assertStatus(200);

        $log = ApiLog::first();
        $this->assertNotNull($log);

        $requestParams = $log->request_parameters;
        $this->assertNotNull($requestParams);
        $this->assertEquals('[REDACTED]', $requestParams['token']);
        $this->assertEquals('[REDACTED]', $requestParams['secret']);
        $this->assertEquals('admin', $requestParams['username']);
    }

    public function test_data_sanitizer_handles_query_params_correctly(): void
    {
        $sanitizer = new DataSanitizer([
            'privacy' => [
                'exclude_fields' => ['token', 'api_key', 'secret'],
            ],
        ]);

        $queryParams = [
            'token' => 'secret-token-value',
            'api_key' => 'sk_test_123456',
            'user_id' => '42',
            'format' => 'json',
        ];

        $sanitized = $sanitizer->sanitizeQueryParams($queryParams);

        $this->assertEquals('[REDACTED]', $sanitized['token']);
        $this->assertEquals('[REDACTED]', $sanitized['api_key']);
        $this->assertEquals('42', $sanitized['user_id']);
        $this->assertEquals('json', $sanitized['format']);
    }

    public function test_it_handles_nested_params_in_query_string(): void
    {
        Route::middleware(LogApiRequests::class)->get('/api/search', function (Request $request) {
            return response()->json(['results' => []]);
        });

        // PHP will parse filters[token] as a nested array
        $response = $this->get('/api/search?filters[token]=secret123&filters[status]=active');

        $response->assertStatus(200);

        $log = ApiLog::first();
        $this->assertNotNull($log);

        $requestParams = $log->request_parameters;
        $this->assertNotNull($requestParams);
        $this->assertArrayHasKey('filters', $requestParams);
        $this->assertEquals('[REDACTED]', $requestParams['filters']['token']);
        $this->assertEquals('active', $requestParams['filters']['status']);
    }
}
