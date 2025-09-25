<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestCapture
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Capture request data from an HTTP request.
     *
     * @return array<string, mixed>
     */
    public function capture(Request $request): array
    {
        $correlationId = $this->generateRequestId($request);

        $data = [
            'method' => $request->getMethod(),
            'endpoint' => $this->getEndpoint($request),
            'headers' => $this->captureHeaders($request),
            'body' => $this->captureBody($request),
            'query_params' => $request->query->all(),
            'ip_address' => $this->getIpAddress($request),
            'user_agent' => $request->userAgent(),
            'user_identifier' => $this->getUserIdentifier($request),
            'correlation_identifier' => $correlationId,
        ];

        // Start with base metadata
        $metadata = [
            'correlation_id' => $correlationId,
            'direction' => 'inbound',
        ];

        // Add custom enrichment data
        if (isset($this->config['enrichment']['custom_callback']) && is_callable($this->config['enrichment']['custom_callback'])) {
            $customData = call_user_func($this->config['enrichment']['custom_callback'], $request);
            if (is_array($customData)) {
                $metadata = array_merge($metadata, $customData);
            }
        }

        $data['metadata'] = $metadata;

        return $data;
    }

    /**
     * Get the endpoint path from the request.
     */
    protected function getEndpoint(Request $request): string
    {
        // Get the path without query string
        $path = $request->path();

        // Normalize the path (ensure it starts with /)
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $path;
    }

    /**
     * Capture request headers.
     *
     * @return array<string, string>
     */
    protected function captureHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $values) {
            // Headers can have multiple values, we'll join them
            $headers[$key] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * Capture request body based on content type and size limits.
     */
    protected function captureBody(Request $request): mixed
    {
        $maxBodySize = $this->config['performance']['max_body_size'] ?? 65536; // 64KB default
        $contentType = $request->header('Content-Type', '');

        // Check if body capture is enabled (based on logging level)
        $loggingLevel = $this->config['level'] ?? 'detailed';
        if ($loggingLevel !== 'full') {
            return null;
        }

        // Handle file uploads - just capture metadata
        if (! empty($request->allFiles())) {
            return $this->captureFileMetadata($request);
        }

        // Check for binary content types
        if ($this->isBinaryContent($contentType)) {
            return [
                '_binary' => true,
                'content_type' => $contentType,
                'size' => (int) $request->header('Content-Length', '0'),
            ];
        }

        // Get the raw content
        $content = $request->getContent();

        // Return null for empty content instead of empty string
        if ($content === '') {
            return null;
        }

        // Check size limit
        if ($maxBodySize > 0 && strlen($content) > $maxBodySize) {
            return [
                '_truncated' => true,
                'original_size' => strlen($content),
                'content' => substr($content, 0, $maxBodySize),
            ];
        }

        // Try to parse JSON content
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Try to parse form data
        if (str_contains($contentType, 'application/x-www-form-urlencoded') ||
            str_contains($contentType, 'multipart/form-data')) {
            $formData = $request->all();

            // Return null if form data is empty
            return empty($formData) ? null : $formData;
        }

        // Return raw content for other types
        return $content;
    }

    /**
     * Capture file upload metadata without the actual file content.
     *
     * @return array<string, mixed>
     */
    protected function captureFileMetadata(Request $request): array
    {
        $files = [];

        foreach ($request->allFiles() as $key => $file) {
            if (is_array($file)) {
                $files[$key] = array_map(fn ($f) => $this->getFileInfo($f), $file);
            } else {
                $files[$key] = $this->getFileInfo($file);
            }
        }

        // Include other non-file form data
        $otherData = array_diff_key($request->all(), $request->allFiles());

        return array_merge($otherData, ['_files' => $files]);
    }

    /**
     * Get file information without the content.
     *
     * @return array<string, mixed>
     */
    protected function getFileInfo($file): array
    {
        if (! $file || ! $file->isValid()) {
            return ['error' => 'Invalid file'];
        }

        return [
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'extension' => $file->getClientOriginalExtension(),
        ];
    }

    /**
     * Check if the content type indicates binary data.
     */
    protected function isBinaryContent(string $contentType): bool
    {
        $binaryTypes = [
            'application/octet-stream',
            'application/pdf',
            'application/zip',
            'application/gzip',
            'image/',
            'video/',
            'audio/',
        ];

        foreach ($binaryTypes as $type) {
            if (str_contains($contentType, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the client IP address, considering proxies.
     */
    protected function getIpAddress(Request $request): ?string
    {
        if (! ($this->config['enrichment']['capture_ip'] ?? true)) {
            return null;
        }

        // Try to get the real IP if behind a proxy
        $ip = $request->header('X-Forwarded-For');
        if ($ip) {
            // X-Forwarded-For can contain multiple IPs, get the first one
            $ips = explode(',', $ip);

            return trim($ips[0]);
        }

        // Try other proxy headers
        $ip = $request->header('X-Real-IP');
        if ($ip) {
            return $ip;
        }

        // Fall back to direct IP
        return $request->ip();
    }

    /**
     * Get the user identifier from the request.
     */
    protected function getUserIdentifier(Request $request): ?string
    {
        if (! ($this->config['enrichment']['capture_user'] ?? true)) {
            return null;
        }

        // Check if user is authenticated
        $user = $request->user();
        if (! $user) {
            return null;
        }

        // Get the configured identifier field
        $identifierField = $this->config['enrichment']['user_identifier'] ?? 'id';

        // Handle different identifier types
        if ($identifierField === 'id') {
            return (string) $user->getKey();
        }

        // Try to get the field value
        if (isset($user->{$identifierField})) {
            return (string) $user->{$identifierField};
        }

        // Fall back to user ID
        return (string) $user->getKey();
    }

    /**
     * Generate a unique request ID.
     */
    protected function generateRequestId(Request $request): string
    {
        // Check if there's an existing correlation ID in headers
        $correlationId = $request->header('X-Correlation-ID')
            ?? $request->header('X-Request-ID')
            ?? $request->header('Request-ID');

        if ($correlationId) {
            return $correlationId;
        }

        // Generate a new UUID
        return (string) Str::uuid();
    }

    /**
     * Check if the request should capture full body based on the method.
     */
    public function shouldCaptureBody(Request $request): bool
    {
        $loggingLevel = $this->config['level'] ?? 'detailed';
        if ($loggingLevel !== 'full') {
            return false;
        }

        // Don't capture body for GET, HEAD, OPTIONS requests
        $method = $request->getMethod();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        return true;
    }
}
