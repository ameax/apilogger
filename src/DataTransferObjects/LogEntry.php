<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\DataTransferObjects;

use Ameax\ApiLogger\Contracts\LogEntryInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

class LogEntry implements Arrayable, Jsonable, LogEntryInterface
{
    private ?Carbon $actualCreatedAt = null;

    public function __construct(
        private readonly string $requestId,
        private readonly string $method,
        private readonly string $endpoint,
        private readonly array $requestHeaders,
        private readonly mixed $requestBody,
        private readonly int $responseCode,
        private readonly array $responseHeaders,
        private readonly mixed $responseBody,
        private readonly float $responseTimeMs,
        private readonly ?string $userIdentifier = null,
        private readonly ?string $ipAddress = null,
        private readonly ?string $userAgent = null,
        ?Carbon $createdAt = null,
        private readonly array $metadata = [],
    ) {
        $this->actualCreatedAt = $createdAt ?? Carbon::now();
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getRequestHeaders(): array
    {
        return $this->requestHeaders;
    }

    public function getRequestBody(): mixed
    {
        return $this->requestBody;
    }

    public function getResponseCode(): int
    {
        return $this->responseCode;
    }

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getResponseBody(): mixed
    {
        return $this->responseBody;
    }

    public function getResponseTimeMs(): float
    {
        return $this->responseTimeMs;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): Carbon
    {
        return $this->actualCreatedAt ?? Carbon::now();
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'method' => $this->method,
            'endpoint' => $this->endpoint,
            'request_headers' => $this->requestHeaders,
            'request_body' => $this->requestBody,
            'response_code' => $this->responseCode,
            'response_headers' => $this->responseHeaders,
            'response_body' => $this->responseBody,
            'response_time_ms' => $this->responseTimeMs,
            'user_identifier' => $this->userIdentifier,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'created_at' => $this->getCreatedAt()->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Create a LogEntry from an array of data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            requestId: $data['request_id'] ?? '',
            method: $data['method'] ?? '',
            endpoint: $data['endpoint'] ?? '',
            requestHeaders: $data['request_headers'] ?? [],
            requestBody: $data['request_body'] ?? null,
            responseCode: $data['response_code'] ?? 0,
            responseHeaders: $data['response_headers'] ?? [],
            responseBody: $data['response_body'] ?? null,
            responseTimeMs: (float) ($data['response_time_ms'] ?? 0),
            userIdentifier: $data['user_identifier'] ?? null,
            ipAddress: $data['ip_address'] ?? null,
            userAgent: $data['user_agent'] ?? null,
            createdAt: isset($data['created_at']) ? Carbon::parse($data['created_at']) : null,
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * Check if this is an error response (4xx or 5xx).
     */
    public function isError(): bool
    {
        return $this->responseCode >= 400;
    }

    /**
     * Check if this is a client error (4xx).
     */
    public function isClientError(): bool
    {
        return $this->responseCode >= 400 && $this->responseCode < 500;
    }

    /**
     * Check if this is a server error (5xx).
     */
    public function isServerError(): bool
    {
        return $this->responseCode >= 500;
    }

    /**
     * Check if this is a successful response (2xx).
     */
    public function isSuccess(): bool
    {
        return $this->responseCode >= 200 && $this->responseCode < 300;
    }
}
