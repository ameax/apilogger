<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Contracts;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface OutboundLoggerInterface
{
    /**
     * Log an outbound API request.
     */
    public function logRequest(RequestInterface $request, array $options = []): string;

    /**
     * Log an outbound API response.
     */
    public function logResponse(
        string $requestId,
        RequestInterface $request,
        ?ResponseInterface $response,
        float $duration,
        array $options = [],
        ?\Throwable $error = null
    ): void;

    /**
     * Determine if the request should be logged.
     */
    public function shouldLog(RequestInterface $request, array $options = []): bool;

    /**
     * Extract metadata from Guzzle options.
     */
    public function extractMetadata(RequestInterface $request, array $options = []): array;
}
