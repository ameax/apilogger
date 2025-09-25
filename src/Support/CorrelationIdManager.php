<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CorrelationIdManager
{
    private static ?string $currentCorrelationId = null;

    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function getCorrelationId(): string
    {
        if (self::$currentCorrelationId === null) {
            self::$currentCorrelationId = $this->extractOrGenerate();
        }

        return self::$currentCorrelationId;
    }

    public function setCorrelationId(string $correlationId): void
    {
        self::$currentCorrelationId = $correlationId;
    }

    public function extractFromRequest(Request $request): ?string
    {
        $headers = $this->getCorrelationHeaders();

        foreach ($headers as $header) {
            if ($request->hasHeader($header)) {
                $value = $request->header($header);
                if (is_string($value) && ! empty($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    public function extractFromArray(array $headers): ?string
    {
        $correlationHeaders = $this->getCorrelationHeaders();

        foreach ($correlationHeaders as $header) {
            $normalizedHeader = strtolower($header);
            foreach ($headers as $key => $value) {
                if (strtolower($key) === $normalizedHeader) {
                    if (is_array($value)) {
                        $value = $value[0] ?? null;
                    }
                    if (is_string($value) && ! empty($value)) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    public function generate(): string
    {
        $method = $this->config['features']['correlation']['generation_method'] ?? 'uuid';

        return match ($method) {
            'uuid' => (string) Str::uuid(),
            'ulid' => (string) Str::ulid(),
            'timestamp' => $this->generateTimestampId(),
            default => (string) Str::uuid(),
        };
    }

    private function generateTimestampId(): string
    {
        $timestamp = microtime(true);
        $random = bin2hex(random_bytes(8));

        return sprintf('%s-%s', $timestamp, $random);
    }

    public function getHeaderName(): string
    {
        return $this->config['features']['correlation']['header_name'] ?? 'X-Correlation-ID';
    }

    public function shouldPropagate(): bool
    {
        return $this->config['features']['correlation']['propagate'] ?? true;
    }

    public function shouldAddToResponse(): bool
    {
        return $this->config['features']['correlation']['add_to_response'] ?? true;
    }

    public function addToHeaders(array &$headers): void
    {
        if (! $this->shouldPropagate()) {
            return;
        }

        $headerName = $this->getHeaderName();
        $correlationId = $this->getCorrelationId();

        $headers[$headerName] = $correlationId;
    }

    public function reset(): void
    {
        self::$currentCorrelationId = null;
    }

    private function extractOrGenerate(): string
    {
        // Try to extract from current request if available
        if (app()->has('request')) {
            $request = app('request');
            $extracted = $this->extractFromRequest($request);
            if ($extracted) {
                return $extracted;
            }
        }

        // Generate new correlation ID
        return $this->generate();
    }

    private function getCorrelationHeaders(): array
    {
        $configured = $this->config['features']['correlation']['headers'] ?? [];

        // Default headers to check
        $defaults = [
            'X-Correlation-ID',
            'X-Request-ID',
            'X-Trace-ID',
            'Correlation-ID',
            'Request-ID',
        ];

        return array_unique(array_merge($configured, $defaults));
    }

    public function isEnabled(): bool
    {
        return $this->config['features']['correlation']['enabled'] ?? true;
    }

    public static function current(): ?string
    {
        return self::$currentCorrelationId;
    }
}
