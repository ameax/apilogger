<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseCapture
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Capture response data from an HTTP response.
     *
     * @param  Response|\Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\Response  $response
     * @return array<string, mixed>
     */
    public function capture($response, float $startTime): array
    {
        $data = [
            'status_code' => $response->getStatusCode(),
            'headers' => $this->captureHeaders($response),
            'body' => $this->captureBody($response),
            'response_time_ms' => $this->calculateResponseTime($startTime),
        ];

        // Add memory usage if configured
        if ($this->config['enrichment']['capture_memory'] ?? false) {
            $data['memory_usage'] = memory_get_peak_usage(true);
        }

        return $data;
    }

    /**
     * Capture response headers.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return array<string, string>
     */
    protected function captureHeaders($response): array
    {
        $headers = [];

        foreach ($response->headers->all() as $key => $values) {
            // Headers can have multiple values, we'll join them
            $headers[$key] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * Capture response body based on response type and size limits.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     */
    protected function captureBody($response): mixed
    {
        $maxBodySize = $this->config['performance']['max_body_size'] ?? 65536; // 64KB default
        $loggingLevel = $this->config['level'] ?? 'detailed';

        // Check if body capture is enabled
        if ($loggingLevel !== 'full') {
            return null;
        }

        // Handle different response types
        if ($response instanceof StreamedResponse) {
            return $this->captureStreamedResponse($response);
        }

        if ($response instanceof BinaryFileResponse) {
            return $this->captureBinaryFileResponse($response);
        }

        if ($response instanceof JsonResponse) {
            return $this->captureJsonResponse($response, $maxBodySize);
        }

        // Handle regular Response
        if ($response instanceof Response) {
            return $this->captureRegularResponse($response, $maxBodySize);
        }

        // For other response types, try to get content
        try {
            $content = $response->getContent();
            if ($content === false) {
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

            return $content;
        } catch (\Exception $e) {
            return [
                '_error' => 'Failed to capture response body',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Capture a streamed response.
     *
     * @return array<string, mixed>
     */
    protected function captureStreamedResponse(StreamedResponse $response): array
    {
        // We cannot capture streamed response content without affecting the stream
        // Just log metadata
        return [
            '_type' => 'streamed',
            'status' => $response->getStatusCode(),
            'note' => 'Content not captured for streamed responses',
        ];
    }

    /**
     * Capture a binary file response.
     *
     * @return array<string, mixed>
     */
    protected function captureBinaryFileResponse(BinaryFileResponse $response): array
    {
        $file = $response->getFile();

        return [
            '_type' => 'binary_file',
            'filename' => $file->getFilename(),
            'path' => $file->getPathname(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
        ];
    }

    /**
     * Capture a JSON response.
     */
    protected function captureJsonResponse(JsonResponse $response, int $maxBodySize): mixed
    {
        // Get the original data (before encoding)
        $data = $response->getData(true); // Get as array

        // Check size by encoding temporarily
        $encoded = json_encode($data);
        if ($maxBodySize > 0 && strlen($encoded) > $maxBodySize) {
            return [
                '_truncated' => true,
                'original_size' => strlen($encoded),
                'preview' => $this->truncateJson($data, $maxBodySize),
            ];
        }

        return $data;
    }

    /**
     * Capture a regular response.
     */
    protected function captureRegularResponse($response, int $maxBodySize): mixed
    {
        $content = $response->getContent();

        if ($content === false) {
            return null;
        }

        // Check if it's JSON content
        $contentType = $response->headers->get('Content-Type', '');
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Check size
                if ($maxBodySize > 0 && strlen($content) > $maxBodySize) {
                    return [
                        '_truncated' => true,
                        'original_size' => strlen($content),
                        'preview' => $this->truncateJson($decoded, $maxBodySize),
                    ];
                }

                return $decoded;
            }
        }

        // Check size for non-JSON content
        if ($maxBodySize > 0 && strlen($content) > $maxBodySize) {
            return [
                '_truncated' => true,
                'original_size' => strlen($content),
                'content' => substr($content, 0, $maxBodySize),
            ];
        }

        return $content;
    }

    /**
     * Truncate JSON data to fit within size limit.
     */
    protected function truncateJson($data, int $maxSize): mixed
    {
        // For indexed arrays, keep only first few items
        if (is_array($data) && array_is_list($data)) {
            $truncated = [];
            $currentSize = 0;

            foreach ($data as $key => $item) {
                $itemJson = json_encode($item);
                $itemSize = strlen($itemJson);

                if ($currentSize + $itemSize > $maxSize) {
                    $truncated['_truncated_at'] = $key;
                    break;
                }

                $truncated[$key] = $item;
                $currentSize += $itemSize;
            }

            return $truncated;
        }

        // For objects/associative arrays, keep structure but truncate nested arrays
        if (is_array($data) || is_object($data)) {
            $array = (array) $data;
            $result = [];
            $currentSize = 0;

            foreach ($array as $key => $value) {
                // If it's an array of items, truncate it
                if (is_array($value) && !empty($value) && isset($value[0])) {
                    $truncatedItems = [];
                    $itemSize = 0;

                    foreach ($value as $idx => $item) {
                        $itemJson = json_encode($item);
                        $itemSize += strlen($itemJson);

                        if ($itemSize > ($maxSize / 2)) { // Use half the size for nested arrays
                            break;
                        }

                        $truncatedItems[] = $item;
                    }

                    $result[$key] = $truncatedItems;
                    if (count($value) > count($truncatedItems)) {
                        $result['_truncated_at'] = $key . '[' . count($truncatedItems) . ']';
                    }
                } elseif (is_string($value) && strlen($value) > 100) {
                    $result[$key] = substr($value, 0, 100).'...';
                } elseif (is_array($value) || is_object($value)) {
                    // Recursively truncate nested structures
                    $result[$key] = $this->truncateJson($value, (int) ($maxSize / count($array)));
                } else {
                    $result[$key] = $value;
                }

                $currentSize += strlen(json_encode($result[$key]));
                if ($currentSize > $maxSize) {
                    $result['_truncated_at'] = $key;
                    break;
                }
            }

            return $result;
        }

        return $data;
    }

    /**
     * Calculate response time in milliseconds.
     */
    protected function calculateResponseTime(float $startTime): float
    {
        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        return round($responseTime, 2);
    }

    /**
     * Check if the response content type indicates binary data.
     */
    protected function isBinaryContentType(string $contentType): bool
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
     * Check if response should be captured based on status code.
     */
    public function shouldCaptureBody($response): bool
    {
        $loggingLevel = $this->config['level'] ?? 'detailed';
        if ($loggingLevel !== 'full') {
            return false;
        }

        // Always capture error responses
        if ($response->getStatusCode() >= 400) {
            return true;
        }

        // Don't capture for HEAD requests
        if (request()->isMethod('HEAD')) {
            return false;
        }

        // Don't capture binary responses unless specifically configured
        $contentType = $response->headers->get('Content-Type', '');
        if ($this->isBinaryContentType($contentType)) {
            return false;
        }

        return true;
    }
}
