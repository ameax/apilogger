<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Contracts;

use Carbon\Carbon;

interface LogEntryInterface
{
    /**
     * Get the unique request identifier.
     */
    public function getRequestId(): string;

    /**
     * Get the HTTP method.
     */
    public function getMethod(): string;

    /**
     * Get the endpoint/URI.
     */
    public function getEndpoint(): string;

    /**
     * Get the request headers.
     *
     * @return array<string, mixed>
     */
    public function getRequestHeaders(): array;

    /**
     * Get the request body.
     *
     * @return array<string, mixed>|string|null
     */
    public function getRequestBody(): mixed;

    /**
     * Get the response status code.
     */
    public function getResponseCode(): int;

    /**
     * Get the response headers.
     *
     * @return array<string, mixed>
     */
    public function getResponseHeaders(): array;

    /**
     * Get the response body.
     *
     * @return array<string, mixed>|string|null
     */
    public function getResponseBody(): mixed;

    /**
     * Get the response time in milliseconds.
     */
    public function getResponseTimeMs(): float;

    /**
     * Get the user identifier.
     */
    public function getUserIdentifier(): ?string;

    /**
     * Get the IP address.
     */
    public function getIpAddress(): ?string;

    /**
     * Get the user agent string.
     */
    public function getUserAgent(): ?string;

    /**
     * Get the timestamp when the log was created.
     */
    public function getCreatedAt(): Carbon;

    /**
     * Get additional metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Convert to JSON representation.
     */
    public function toJson(): string;
}
