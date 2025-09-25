<?php

declare(strict_types=1);

namespace Tests\Unit\Outbound;

use Ameax\ApiLogger\Contracts\StorageInterface;
use Ameax\ApiLogger\Outbound\OutboundApiLogger;
use Ameax\ApiLogger\Outbound\OutboundFilterService;
use Ameax\ApiLogger\Services\DataSanitizer;
use Ameax\ApiLogger\StorageManager;
use Ameax\ApiLogger\Support\CorrelationIdManager;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Mockery;
use Orchestra\Testbench\TestCase;

class JsonBodyHandlingTest extends TestCase
{
    protected OutboundApiLogger $logger;
    protected $storageManagerMock;
    protected $storageMock;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('apilogger.features.outbound.enabled', true);
        Config::set('apilogger.level', 'full');

        $this->storageMock = Mockery::mock(StorageInterface::class);
        $this->storageManagerMock = Mockery::mock(StorageManager::class);
        $this->storageManagerMock->shouldReceive('store')->andReturn($this->storageMock);

        $this->logger = new OutboundApiLogger(
            $this->storageManagerMock,
            new DataSanitizer(),
            new OutboundFilterService(),
            new CorrelationIdManager(),
            Config::get('apilogger')
        );
    }

    public function test_it_stores_json_request_body_as_array_not_string(): void
    {
        $jsonBody = '{"id":1,"title":"Test Title","body":"Test Body","userId":1}';
        $request = new Request('POST', 'https://api.example.com/posts',
            ['Content-Type' => 'application/json'],
            $jsonBody
        );

        $response = new Response(200,
            ['Content-Type' => 'application/json'],
            '{"success":true}'
        );

        $this->storageMock->shouldReceive('store')->once()
            ->withArgs(function ($logEntry) {
                // Check that request body is stored as array, not as JSON string
                $requestBody = $logEntry->getRequestBody();
                $this->assertIsArray($requestBody);
                $this->assertEquals(1, $requestBody['id']);
                $this->assertEquals('Test Title', $requestBody['title']);

                // Check that response body is also stored as array
                $responseBody = $logEntry->getResponseBody();
                $this->assertIsArray($responseBody);
                $this->assertTrue($responseBody['success']);

                return true;
            });

        $this->logger->logResponse(
            'test-id',
            $request,
            $response,
            100.0
        );
    }

    public function test_it_stores_empty_request_body_as_null_not_empty_string(): void
    {
        $request = new Request('GET', 'https://api.example.com/posts');
        $response = new Response(200, [], '');

        $this->storageMock->shouldReceive('store')->once()
            ->withArgs(function ($logEntry) {
                // Check that empty request body is stored as null
                $this->assertNull($logEntry->getRequestBody());

                // Check that empty response body is also null
                $this->assertNull($logEntry->getResponseBody());

                return true;
            });

        $this->logger->logResponse(
            'test-id',
            $request,
            $response,
            100.0
        );
    }

    public function test_it_handles_non_json_content_properly(): void
    {
        $request = new Request('POST', 'https://api.example.com/posts',
            ['Content-Type' => 'text/plain'],
            'This is plain text'
        );

        $response = new Response(200,
            ['Content-Type' => 'text/html'],
            '<html><body>HTML Response</body></html>'
        );

        $this->storageMock->shouldReceive('store')->once()
            ->withArgs(function ($logEntry) {
                // Non-JSON should be stored as string
                $this->assertIsString($logEntry->getRequestBody());
                $this->assertEquals('This is plain text', $logEntry->getRequestBody());

                $this->assertIsString($logEntry->getResponseBody());
                $this->assertEquals('<html><body>HTML Response</body></html>', $logEntry->getResponseBody());

                return true;
            });

        $this->logger->logResponse(
            'test-id',
            $request,
            $response,
            100.0
        );
    }

    public function test_it_handles_json_response_body_properly(): void
    {
        $request = new Request('GET', 'https://api.example.com/posts');

        $jsonResponse = '{"data":[{"id":1,"name":"Item 1"},{"id":2,"name":"Item 2"}],"total":2}';
        $response = new Response(200,
            ['Content-Type' => 'application/json'],
            $jsonResponse
        );

        $this->storageMock->shouldReceive('store')->once()
            ->withArgs(function ($logEntry) {
                $responseBody = $logEntry->getResponseBody();

                // Should be stored as array, not JSON string
                $this->assertIsArray($responseBody);
                $this->assertArrayHasKey('data', $responseBody);
                $this->assertIsArray($responseBody['data']);
                $this->assertCount(2, $responseBody['data']);
                $this->assertEquals(2, $responseBody['total']);

                return true;
            });

        $this->logger->logResponse(
            'test-id',
            $request,
            $response,
            100.0
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}