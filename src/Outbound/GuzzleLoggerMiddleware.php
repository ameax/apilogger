<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Outbound;

use Ameax\ApiLogger\Contracts\OutboundLoggerInterface;
use Ameax\ApiLogger\Support\CorrelationIdManager;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class GuzzleLoggerMiddleware
{
    public function __construct(
        private OutboundLoggerInterface $logger,
        private ?CorrelationIdManager $correlationIdManager = null,
        private ?ServiceDetector $serviceDetector = null
    ) {}

    /**
     * Create the middleware callable.
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            // Detect service if detector is available
            $serviceClass = $this->detectService($options);

            // Add service class to options for filtering
            $options['_service_class'] = $serviceClass;

            if (! $this->logger->shouldLog($request, $options)) {
                return $handler($request, $options);
            }

            // Add correlation ID to request headers if enabled
            $request = $this->addCorrelationId($request, $options);

            $startTime = microtime(true);
            $requestId = $this->logger->logRequest($request, $options);

            $promise = $handler($request, $options);

            return $promise->then(
                function (ResponseInterface $response) use ($request, $requestId, $startTime, $options) {
                    $duration = (microtime(true) - $startTime) * 1000;

                    $this->logger->logResponse(
                        $requestId,
                        $request,
                        $response,
                        $duration,
                        $options
                    );

                    $response->getBody()->rewind();

                    return $response;
                },
                function ($reason) use ($request, $requestId, $startTime, $options) {
                    $duration = (microtime(true) - $startTime) * 1000;

                    $error = $reason instanceof \Throwable ? $reason : new \RuntimeException((string) $reason);

                    if (method_exists($reason, 'getResponse')) {
                        $response = $reason->getResponse();
                    } else {
                        $response = null;
                    }

                    $this->logger->logResponse(
                        $requestId,
                        $request,
                        $response,
                        $duration,
                        $options,
                        $error
                    );

                    throw $reason;
                }
            );
        };
    }

    /**
     * Static factory method for easier registration.
     */
    public static function create(OutboundLoggerInterface $logger): self
    {
        return new self($logger);
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
    private function detectService(array $options): ?string
    {
        // Check if service class is explicitly provided in options
        if (isset($options['service_class'])) {
            return $options['service_class'];
        }

        // Use service detector if available
        if ($this->serviceDetector) {
            return $this->serviceDetector->detect($options);
        }

        return null;
    }
}
