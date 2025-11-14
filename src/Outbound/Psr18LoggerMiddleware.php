<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Outbound;

use Ameax\ApiLogger\Contracts\OutboundLoggerInterface;
use Ameax\ApiLogger\Support\CorrelationIdManager;
use Http\Client\HttpClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 HTTP Client decorator that logs requests and responses.
 *
 * This middleware wraps any PSR-18 compliant HTTP client and logs
 * all requests/responses through the ApiLogger system.
 *
 * Also implements HttpClient for libraries like Typesense that use HTTPlug.
 */
class Psr18LoggerMiddleware implements ClientInterface, HttpClient
{
    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    public function __construct(
        private ClientInterface $client,
        private OutboundLoggerInterface $logger,
        private ?CorrelationIdManager $correlationIdManager = null,
        private ?ServiceDetector $serviceDetector = null,
        private array $options = []
    ) {
        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * Static factory method for easier creation.
     */
    public static function create(
        ClientInterface $client,
        OutboundLoggerInterface $logger,
        ?CorrelationIdManager $correlationIdManager = null,
        ?ServiceDetector $serviceDetector = null,
        array $options = []
    ): self {
        return new self($client, $logger, $correlationIdManager, $serviceDetector, $options);
    }

    /**
     * Add correlation ID to request headers.
     */
    private function addCorrelationId(RequestInterface $request, array $options): RequestInterface
    {
        if (! $this->correlationIdManager || ! $this->correlationIdManager->isEnabled()) {
            return $request;
        }

        // Check if correlation ID is already in options
        $correlationId = $options['correlation_id'] ?? $this->correlationIdManager->getCorrelationId();

        if ($this->correlationIdManager->shouldPropagate()) {
            $headerName = $this->correlationIdManager->getHeaderName();
            $request = $request->withHeader($headerName, $correlationId);
        }

        return $request;
    }

    /**
     * Detect service class from options or backtrace.
     */
    private function detectService(): ?string
    {
        // Check if service class is explicitly provided in options
        if (isset($this->options['service_class'])) {
            return $this->options['service_class'];
        }

        // Use service detector if available
        if ($this->serviceDetector) {
            return $this->serviceDetector->detect($this->options);
        }

        return null;
    }

    /**
     * Send a request via HttpClient interface (HTTPlug).
     *
     * This method is used by libraries like Typesense that use HTTPlug/HttpMethodsClient.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->logRequest($request);
    }

    /**
     * Send a request via HttpClient::send() (HTTPlug interface).
     */
    public function send(string $method, $uri, array $headers = [], $body = null): ResponseInterface
    {
        // Build PSR-7 request from parameters
        $request = $this->requestFactory->createRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            if (is_string($body)) {
                $stream = $this->streamFactory->createStream($body);
            } elseif ($body instanceof \Psr\Http\Message\StreamInterface) {
                $stream = $body;
            } else {
                $stream = $this->streamFactory->createStream((string) $body);
            }
            $request = $request->withBody($stream);
        }

        return $this->logRequest($request);
    }

    /**
     * Internal method to log and send a request.
     */
    private function logRequest(RequestInterface $request): ResponseInterface
    {
        // Detect service if detector is available
        $serviceClass = $this->detectService();

        // Prepare options with service class
        $options = array_merge($this->options, [
            '_service_class' => $serviceClass,
        ]);

        // Check if we should log this request
        if (! $this->logger->shouldLog($request, $options)) {
            return $this->client->sendRequest($request);
        }

        // Add correlation ID to request headers if enabled
        $request = $this->addCorrelationId($request, $options);

        $startTime = microtime(true);
        $requestId = $this->logger->logRequest($request, $options);

        try {
            $response = $this->client->sendRequest($request);
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logger->logResponse(
                $requestId,
                $request,
                $response,
                $duration,
                $options
            );

            return $response;
        } catch (\Throwable $error) {
            $duration = (microtime(true) - $startTime) * 1000;

            // Try to extract response from error if available
            $response = null;
            if (method_exists($error, 'getResponse')) {
                $response = $error->getResponse();
            }

            $this->logger->logResponse(
                $requestId,
                $request,
                $response,
                $duration,
                array_merge($options, ['_is_error' => true]),
                $error
            );

            throw $error;
        }
    }

    /**
     * Get the underlying client.
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }
}
