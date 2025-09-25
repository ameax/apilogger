<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Models;

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $method
 * @property string $endpoint
 * @property array|null $request_headers
 * @property mixed $request_body
 * @property int $response_code
 * @property array|null $response_headers
 * @property mixed $response_body
 * @property float $response_time_ms
 * @property string $direction
 * @property string|null $service
 * @property string|null $correlation_identifier
 * @property int $retry_attempt
 * @property string|null $user_identifier
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array|null $metadata
 * @property string|null $comment
 * @property bool $is_marked
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
        'method',
        'endpoint',
        'request_headers',
        'request_body',
        'response_code',
        'response_headers',
        'response_body',
        'response_time_ms',
        'direction',
        'service',
        'correlation_identifier',
        'retry_attempt',
        'user_identifier',
        'ip_address',
        'user_agent',
        'metadata',
        'comment',
        'is_marked',
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
        'retry_attempt' => 'integer',
        'is_marked' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Convert the model to a LogEntry DTO.
     */
    public function toLogEntry(): LogEntry
    {
        // Build metadata array from native columns plus any extra metadata
        $fullMetadata = array_merge($this->metadata ?? [], [
            'direction' => $this->direction,
            'service' => $this->service,
            'correlation_id' => $this->correlation_identifier,
            'retry_attempt' => $this->retry_attempt,
        ]);

        return new LogEntry(
            requestId: (string) $this->id,
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
            metadata: $fullMetadata,
        );
    }

    /**
     * Create a model from a LogEntry DTO.
     */
    public static function fromLogEntry(LogEntry $entry): self
    {
        $metadata = $entry->getMetadata();

        // Extract native columns from metadata
        $direction = $metadata['direction'] ?? 'inbound';
        $service = $metadata['service'] ?? null;
        // Use correlation_id from metadata, or fall back to requestId for tracking
        $correlationId = $metadata['correlation_id'] ?? $entry->getRequestId();
        $retryAttempt = $metadata['retry_attempt'] ?? 0;

        // Remove these from metadata to avoid duplication
        $cleanMetadata = array_diff_key($metadata, array_flip([
            'direction', 'service', 'correlation_id', 'retry_attempt',
        ]));

        return new self([
            'method' => $entry->getMethod(),
            'endpoint' => $entry->getEndpoint(),
            'request_headers' => $entry->getRequestHeaders(),
            'request_body' => $entry->getRequestBody(),
            'response_code' => $entry->getResponseCode(),
            'response_headers' => $entry->getResponseHeaders(),
            'response_body' => $entry->getResponseBody(),
            'response_time_ms' => $entry->getResponseTimeMs(),
            'direction' => $direction,
            'service' => $service,
            'correlation_identifier' => $correlationId,
            'retry_attempt' => $retryAttempt,
            'user_identifier' => $entry->getUserIdentifier(),
            'ip_address' => $entry->getIpAddress(),
            'user_agent' => $entry->getUserAgent(),
            'metadata' => $cleanMetadata ?: null,
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
     * Scope for marked logs.
     */
    public function scopeMarked(Builder $query): Builder
    {
        return $query->where('is_marked', true);
    }

    /**
     * Scope for logs with comments.
     */
    public function scopeWithComments(Builder $query): Builder
    {
        return $query->whereNotNull('comment');
    }

    /**
     * Scope for logs that should be preserved (marked or with comments).
     */
    public function scopePreserved(Builder $query): Builder
    {
        return $query->where('is_marked', true)
            ->orWhereNotNull('comment');
    }

    /**
     * Scope for logs that are not preserved (not marked and without comments).
     */
    public function scopeNotPreserved(Builder $query): Builder
    {
        return $query->where('is_marked', false)
            ->whereNull('comment');
    }

    /**
     * Scope for inbound API requests.
     */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', 'inbound');
    }

    /**
     * Scope for outbound API requests.
     */
    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * Scope for logs by service name.
     */
    public function scopeForService(Builder $query, string $service): Builder
    {
        return $query->where('service', $service);
    }

    /**
     * Scope for logs with a specific correlation ID.
     */
    public function scopeWithCorrelation(Builder $query, string $correlationId): Builder
    {
        return $query->where('correlation_identifier', $correlationId);
    }

    /**
     * Scope for failed requests (4xx and 5xx responses).
     */
    public function scopeFailedRequests(Builder $query): Builder
    {
        return $query->where('response_code', '>=', 400);
    }

    /**
     * Scope for slow requests above a threshold.
     *
     * @param  float  $thresholdMs  Threshold in milliseconds
     */
    public function scopeSlowRequests(Builder $query, float $thresholdMs = 5000): Builder
    {
        return $query->where('response_time_ms', '>', $thresholdMs);
    }

    /**
     * Scope for today's logs.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', Carbon::today());
    }

    /**
     * Scope for logs with retry attempts.
     */
    public function scopeWithRetries(Builder $query): Builder
    {
        return $query->where('retry_attempt', '>', 0);
    }

    /**
     * Scope for logs by retry attempt number.
     */
    public function scopeRetryAttempt(Builder $query, int $attempt): Builder
    {
        return $query->where('retry_attempt', $attempt);
    }

    // Direction is now a native column, no accessor needed

    // Service is now a native column, no accessor needed

    /**
     * Get the correlation ID attribute (alias for correlation_identifier).
     */
    public function getCorrelationIdAttribute(): ?string
    {
        return $this->correlation_identifier;
    }

    // Retry attempt is now a native column, no accessor needed

    /**
     * Check if this is an outbound request.
     */
    public function getIsOutboundAttribute(): bool
    {
        return $this->direction === 'outbound';
    }

    /**
     * Check if this is an inbound request.
     */
    public function getIsInboundAttribute(): bool
    {
        return $this->direction === 'inbound';
    }

    /**
     * Get the connection time from metadata (if available).
     */
    public function getConnectionTimeAttribute(): ?float
    {
        return isset($this->metadata['connection_time_ms'])
            ? (float) $this->metadata['connection_time_ms']
            : null;
    }

    /**
     * Get the total time from metadata (if available).
     */
    public function getTotalTimeAttribute(): ?float
    {
        return isset($this->metadata['total_time_ms'])
            ? (float) $this->metadata['total_time_ms']
            : $this->response_time_ms;
    }

    /**
     * Check if this request was retried.
     */
    public function getWasRetriedAttribute(): bool
    {
        return $this->retry_attempt > 0;
    }

    /**
     * Get all related logs in the same correlation chain.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ApiLog>
     */
    public function getCorrelationChain()
    {
        if (! $this->correlation_identifier) {
            $collection = new \Illuminate\Database\Eloquent\Collection;
            $collection->push($this);

            return $collection;
        }

        return static::withCorrelation($this->correlation_identifier)
            ->orderBy('created_at')
            ->get();
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
