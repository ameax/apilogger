<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Models;

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $request_id
 * @property string $method
 * @property string $endpoint
 * @property array|null $request_headers
 * @property mixed $request_body
 * @property int $response_code
 * @property array|null $response_headers
 * @property mixed $response_body
 * @property float $response_time_ms
 * @property string|null $user_identifier
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ApiLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'api_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'request_id',
        'method',
        'endpoint',
        'request_headers',
        'request_body',
        'response_code',
        'response_headers',
        'response_body',
        'response_time_ms',
        'user_identifier',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'request_headers' => 'array',
        'request_body' => 'array',
        'response_headers' => 'array',
        'response_body' => 'array',
        'metadata' => 'array',
        'response_time_ms' => 'float',
        'response_code' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Convert the model to a LogEntry DTO.
     */
    public function toLogEntry(): LogEntry
    {
        return new LogEntry(
            requestId: $this->request_id,
            method: $this->method,
            endpoint: $this->endpoint,
            requestHeaders: $this->request_headers ?? [],
            requestBody: $this->request_body,
            responseCode: $this->response_code,
            responseHeaders: $this->response_headers ?? [],
            responseBody: $this->response_body,
            responseTimeMs: $this->response_time_ms,
            userIdentifier: $this->user_identifier,
            ipAddress: $this->ip_address,
            userAgent: $this->user_agent,
            createdAt: $this->created_at,
            metadata: $this->metadata ?? [],
        );
    }

    /**
     * Create a model from a LogEntry DTO.
     */
    public static function fromLogEntry(LogEntry $entry): self
    {
        return new self([
            'request_id' => $entry->getRequestId(),
            'method' => $entry->getMethod(),
            'endpoint' => $entry->getEndpoint(),
            'request_headers' => $entry->getRequestHeaders(),
            'request_body' => $entry->getRequestBody(),
            'response_code' => $entry->getResponseCode(),
            'response_headers' => $entry->getResponseHeaders(),
            'response_body' => $entry->getResponseBody(),
            'response_time_ms' => $entry->getResponseTimeMs(),
            'user_identifier' => $entry->getUserIdentifier(),
            'ip_address' => $entry->getIpAddress(),
            'user_agent' => $entry->getUserAgent(),
            'metadata' => $entry->getMetadata(),
            'created_at' => $entry->getCreatedAt(),
        ]);
    }

    /**
     * Scope for error logs (4xx and 5xx responses).
     */
    public function scopeErrors(Builder $query): Builder
    {
        return $query->where('response_code', '>=', 400);
    }

    /**
     * Scope for successful logs (2xx responses).
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereBetween('response_code', [200, 299]);
    }

    /**
     * Scope for client errors (4xx responses).
     */
    public function scopeClientErrors(Builder $query): Builder
    {
        return $query->whereBetween('response_code', [400, 499]);
    }

    /**
     * Scope for server errors (5xx responses).
     */
    public function scopeServerErrors(Builder $query): Builder
    {
        return $query->where('response_code', '>=', 500);
    }

    /**
     * Scope for logs by user.
     */
    public function scopeForUser(Builder $query, string $userIdentifier): Builder
    {
        return $query->where('user_identifier', $userIdentifier);
    }

    /**
     * Scope for logs by endpoint.
     */
    public function scopeForEndpoint(Builder $query, string $endpoint, ?string $method = null): Builder
    {
        $query->where('endpoint', $endpoint);

        if ($method !== null) {
            $query->where('method', $method);
        }

        return $query;
    }

    /**
     * Scope for logs within a date range.
     */
    public function scopeBetweenDates(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Scope for slow requests.
     */
    public function scopeSlow(Builder $query, float $thresholdMs = 1000): Builder
    {
        return $query->where('response_time_ms', '>', $thresholdMs);
    }

    /**
     * Scope for logs older than specified days.
     */
    public function scopeOlderThan(Builder $query, int $days): Builder
    {
        return $query->where('created_at', '<', Carbon::now()->subDays($days));
    }

    /**
     * Check if this is an error response.
     */
    public function isError(): bool
    {
        return $this->response_code >= 400;
    }

    /**
     * Check if this is a successful response.
     */
    public function isSuccess(): bool
    {
        return $this->response_code >= 200 && $this->response_code < 300;
    }
}
