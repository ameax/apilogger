<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Outbound;

use Ameax\ApiLogger\Contracts\OutboundLoggerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class GuzzleLoggerMiddleware
{
    public function __construct(
        private OutboundLoggerInterface $logger
    ) {}

    /**
     * Create the middleware callable.
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            if (! $this->logger->shouldLog($request, $options)) {
                return $handler($request, $options);
            }

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
}
