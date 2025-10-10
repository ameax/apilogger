<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Services\Export;

use Ameax\ApiLogger\Models\ApiLog;

class HarExportService extends ApiLogExportService
{
    /**
     * Generate HAR (HTTP Archive) format from ApiLog entry.
     *
     * @return array<string, mixed>
     */
    public function generate(ApiLog $apiLog): array
    {
        return [
            'log' => [
                'version' => '1.2',
                'creator' => [
                    'name' => 'API Logger',
                    'version' => '1.0',
                ],
                'entries' => [
                    $this->buildEntry($apiLog),
                ],
            ],
        ];
    }

    /**
     * Build a single HAR entry from ApiLog.
     *
     * @return array<string, mixed>
     */
    protected function buildEntry(ApiLog $apiLog): array
    {
        $entry = [
            'startedDateTime' => $apiLog->created_at->toIso8601String(),
            'time' => (float) $apiLog->response_time_ms,
            'request' => $this->buildRequest($apiLog),
            'response' => $this->buildResponse($apiLog),
            'cache' => new \stdClass(),
            'timings' => $this->buildTimings($apiLog),
        ];

        // Add custom fields for API Logger specific data
        if ($apiLog->comment) {
            $entry['comment'] = $apiLog->comment;
        }

        if ($apiLog->service) {
            $entry['_service'] = $apiLog->service;
        }

        if ($apiLog->direction) {
            $entry['_direction'] = $apiLog->direction;
        }

        if ($apiLog->correlation_identifier) {
            $entry['_correlationId'] = $apiLog->correlation_identifier;
        }

        if ($apiLog->retry_attempt > 0) {
            $entry['_retryAttempt'] = $apiLog->retry_attempt;
        }

        if ($apiLog->user_identifier) {
            $entry['_userIdentifier'] = $apiLog->user_identifier;
        }

        if ($apiLog->ip_address) {
            $entry['_ipAddress'] = $apiLog->ip_address;
        }

        return $entry;
    }

    /**
     * Build request object for HAR entry.
     *
     * @return array<string, mixed>
     */
    protected function buildRequest(ApiLog $apiLog): array
    {
        $request = [
            'method' => $apiLog->method,
            'url' => $apiLog->endpoint,
            'httpVersion' => 'HTTP/1.1',
            'cookies' => [],
            'headers' => $this->buildHeaders($apiLog->request_headers),
            'queryString' => $this->buildQueryString($apiLog->request_parameters),
            'headersSize' => -1,
            'bodySize' => $apiLog->request_size,
        ];

        // Add POST data if present
        if ($apiLog->request_body) {
            $request['postData'] = [
                'mimeType' => $this->getMimeType($apiLog->request_headers, 'application/json'),
                'text' => $this->encodeBody($apiLog->request_body),
            ];
        }

        return $request;
    }

    /**
     * Build response object for HAR entry.
     *
     * @return array<string, mixed>
     */
    protected function buildResponse(ApiLog $apiLog): array
    {
        return [
            'status' => $apiLog->response_code,
            'statusText' => $this->getStatusText($apiLog->response_code),
            'httpVersion' => 'HTTP/1.1',
            'cookies' => [],
            'headers' => $this->buildHeaders($apiLog->response_headers),
            'content' => [
                'size' => $apiLog->response_size,
                'mimeType' => $this->getMimeType($apiLog->response_headers, 'application/json'),
                'text' => $this->encodeBody($apiLog->response_body),
            ],
            'redirectURL' => '',
            'headersSize' => -1,
            'bodySize' => $apiLog->response_size,
        ];
    }

    /**
     * Build timings object for HAR entry.
     *
     * @return array<string, mixed>
     */
    protected function buildTimings(ApiLog $apiLog): array
    {
        return [
            'blocked' => -1,
            'dns' => -1,
            'connect' => -1,
            'send' => -1,
            'wait' => (float) $apiLog->response_time_ms,
            'receive' => -1,
            'ssl' => -1,
        ];
    }
}
