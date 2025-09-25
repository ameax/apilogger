<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Outbound;

use Ameax\ApiLogger\Contracts\OutboundLoggerInterface;
use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Services\DataSanitizer;
use Ameax\ApiLogger\StorageManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class OutboundApiLogger implements OutboundLoggerInterface
{
    public function __construct(
        private StorageManager $storageManager,
        private DataSanitizer $dataSanitizer,
        private array $config = []
    ) {
        $this->config = empty($config) ? Config::get('apilogger', []) : $config;
    }

    public function logRequest(RequestInterface $request, array $options = []): string
    {
        return Str::uuid()->toString();
    }

    public function logResponse(
        string $requestId,
        RequestInterface $request,
        ?ResponseInterface $response,
        float $duration,
        array $options = [],
        ?\Throwable $error = null
    ): void {
        if (! $this->isOutboundLoggingEnabled()) {
            return;
        }

        $metadata = $this->extractMetadata($request, $options);
        $metadata['direction'] = 'outbound';
        $metadata['duration_ms'] = $duration;

        if ($error !== null) {
            $metadata['error'] = [
                'type' => get_class($error),
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
            ];
        }

        $requestBody = (string) $request->getBody();
        $request->getBody()->rewind();

        $responseBody = null;
        $responseCode = 0;
        $responseHeaders = [];

        if ($response !== null) {
            $responseBody = (string) $response->getBody();
            $response->getBody()->rewind();
            $responseCode = $response->getStatusCode();
            $responseHeaders = $this->normalizeHeaders($response->getHeaders());
        } elseif ($error !== null) {
            $responseCode = 500;
        }

        $sanitizedRequestBody = $this->dataSanitizer->sanitizeBody($requestBody);
        $sanitizedResponseBody = $responseBody !== null ? $this->dataSanitizer->sanitizeBody($responseBody) : null;
        $sanitizedRequestHeaders = $this->dataSanitizer->sanitizeHeaders($this->normalizeHeaders($request->getHeaders()));
        $sanitizedResponseHeaders = $this->dataSanitizer->sanitizeHeaders($responseHeaders);

        $logEntry = new LogEntry(
            requestId: $requestId,
            method: $request->getMethod(),
            endpoint: (string) $request->getUri(),
            requestHeaders: $sanitizedRequestHeaders,
            requestBody: $sanitizedRequestBody,
            responseCode: $responseCode,
            responseHeaders: $sanitizedResponseHeaders,
            responseBody: $sanitizedResponseBody,
            responseTimeMs: $duration,
            userIdentifier: $this->extractUserIdentifier($options),
            ipAddress: null,
            userAgent: $request->getHeaderLine('User-Agent'),
            createdAt: Carbon::now(),
            metadata: $metadata
        );

        $this->storageManager->store()->store($logEntry);
    }

    public function shouldLog(RequestInterface $request, array $options = []): bool
    {
        if (! $this->isOutboundLoggingEnabled()) {
            return false;
        }

        if (isset($options['log_disabled']) && $options['log_disabled'] === true) {
            return false;
        }

        $host = $request->getUri()->getHost();
        $excludedHosts = $this->config['outbound']['excluded_hosts'] ?? [];

        foreach ($excludedHosts as $pattern) {
            if (Str::is($pattern, $host)) {
                return false;
            }
        }

        $method = strtolower($request->getMethod());
        $excludedMethods = array_map('strtolower', $this->config['filtering']['exclude_methods'] ?? []);

        if (in_array($method, $excludedMethods)) {
            return false;
        }

        return true;
    }

    public function extractMetadata(RequestInterface $request, array $options = []): array
    {
        $uri = $request->getUri();

        $metadata = [
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'scheme' => $uri->getScheme(),
            'path' => $uri->getPath(),
            'query' => $uri->getQuery(),
        ];

        if (isset($options['service_name'])) {
            $metadata['service_name'] = $options['service_name'];
        }

        if (isset($options['service'])) {
            $metadata['service'] = $options['service'];
        }

        if (isset($options['correlation_id'])) {
            $metadata['correlation_id'] = $options['correlation_id'];
        }

        if (isset($options['retry_attempt'])) {
            $metadata['retry_attempt'] = $options['retry_attempt'];
        }

        if (isset($options['timeout'])) {
            $metadata['timeout'] = $options['timeout'];
        }

        $metadata['environment'] = app()->environment();

        return $metadata;
    }

    private function isOutboundLoggingEnabled(): bool
    {
        $enabled = $this->config['enabled'] ?? true;
        $outboundEnabled = $this->config['outbound']['enabled'] ?? true;

        return $enabled && $outboundEnabled;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[$name] = is_array($values) ? implode(', ', $values) : $values;
        }

        return $normalized;
    }

    private function extractUserIdentifier(array $options): ?string
    {
        if (isset($options['user_identifier'])) {
            return (string) $options['user_identifier'];
        }

        if (auth()->check()) {
            return (string) auth()->id();
        }

        return null;
    }
}
