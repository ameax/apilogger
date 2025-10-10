<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Services\Export;

abstract class ApiLogExportService
{
    /**
     * Build headers array from JSON headers.
     *
     * @param  array<string, mixed>|null  $headers
     * @return array<int, array<string, string>>
     */
    protected function buildHeaders(?array $headers): array
    {
        if (! $headers) {
            return [];
        }

        $harHeaders = [];
        foreach ($headers as $name => $value) {
            $harHeaders[] = [
                'name' => (string) $name,
                'value' => is_array($value) ? implode(', ', $value) : (string) $value,
            ];
        }

        return $harHeaders;
    }

    /**
     * Build query string array from request parameters.
     *
     * @param  array<string, mixed>|null  $parameters
     * @return array<int, array<string, string>>
     */
    protected function buildQueryString(?array $parameters): array
    {
        if (! $parameters) {
            return [];
        }

        $queryString = [];
        foreach ($parameters as $name => $value) {
            $queryString[] = [
                'name' => (string) $name,
                'value' => is_array($value) ? (json_encode($value) ?: '[]') : (string) $value,
            ];
        }

        return $queryString;
    }

    /**
     * Encode body as JSON string.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function encodeBody(?array $body): string
    {
        if (! $body) {
            return '';
        }

        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Get MIME type from headers.
     *
     * @param  array<string, mixed>|null  $headers
     */
    protected function getMimeType(?array $headers, string $default = 'application/octet-stream'): string
    {
        if (! $headers) {
            return $default;
        }

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $contentType = is_array($value) ? $value[0] : $value;

                // Remove charset if present
                return explode(';', $contentType)[0];
            }
        }

        return $default;
    }

    /**
     * Get HTTP status text from status code.
     */
    protected function getStatusText(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Unknown',
        };
    }
}
