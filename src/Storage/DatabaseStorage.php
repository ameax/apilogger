<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Storage;

use Ameax\ApiLogger\Contracts\StorageInterface;
use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Models\ApiLog;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DatabaseStorage implements StorageInterface
{
    protected Connection $connection;

    protected string $table;

    protected int $batchSize;

    public function __construct(
        protected DatabaseManager $db,
        array $config = []
    ) {
        $connectionName = $config['connection'] ?? config('database.default');
        $this->connection = $this->db->connection($connectionName);
        $this->table = $config['table'] ?? 'api_logs';
        $this->batchSize = $config['batch_size'] ?? 100;
    }

    /**
     * {@inheritdoc}
     */
    public function store(LogEntry $entry): bool
    {
        try {
            $model = ApiLog::fromLogEntry($entry);

            return $model->save();
        } catch (QueryException $e) {
            Log::error('Failed to store API log entry', [
                'request_id' => $entry->getRequestId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function storeBatch(Collection $entries): int
    {
        if ($entries->isEmpty()) {
            return 0;
        }

        $stored = 0;

        try {
            // Process in chunks for better performance
            foreach ($entries->chunk($this->batchSize) as $chunk) {
                $data = $chunk->map(function (LogEntry $entry) {
                    $model = ApiLog::fromLogEntry($entry);
                    $attributes = $model->getAttributes();
                    $attributes['created_at'] = $entry->getCreatedAt()->toDateTimeString();
                    $attributes['updated_at'] = Carbon::now()->toDateTimeString();

                    // Ensure arrays are JSON encoded for batch insert
                    foreach (['request_headers', 'request_body', 'response_headers', 'response_body', 'metadata'] as $field) {
                        if (isset($attributes[$field]) && is_array($attributes[$field])) {
                            $attributes[$field] = json_encode($attributes[$field]);
                        }
                    }

                    return $attributes;
                })->toArray();

                $inserted = $this->connection->table($this->table)->insert($data);

                if ($inserted) {
                    $stored += count($chunk);
                }
            }
        } catch (QueryException $e) {
            Log::error('Failed to store batch API log entries', [
                'count' => $entries->count(),
                'stored' => $stored,
                'error' => $e->getMessage(),
            ]);
        }

        return $stored;
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(array $criteria = [], int $limit = 100, int $offset = 0): Collection
    {
        $query = ApiLog::query();

        // Apply criteria filters
        if (isset($criteria['request_id'])) {
            $query->where('request_id', $criteria['request_id']);
        }

        if (isset($criteria['method'])) {
            $query->where('method', $criteria['method']);
        }

        if (isset($criteria['endpoint'])) {
            $query->where('endpoint', $criteria['endpoint']);
        }

        if (isset($criteria['user_identifier'])) {
            $query->where('user_identifier', $criteria['user_identifier']);
        }

        if (isset($criteria['response_code'])) {
            $query->where('response_code', $criteria['response_code']);
        }

        if (isset($criteria['from_date'])) {
            $query->where('created_at', '>=', Carbon::parse($criteria['from_date']));
        }

        if (isset($criteria['to_date'])) {
            $query->where('created_at', '<=', Carbon::parse($criteria['to_date']));
        }

        if (isset($criteria['min_response_time'])) {
            $query->where('response_time_ms', '>=', $criteria['min_response_time']);
        }

        if (isset($criteria['is_error']) && $criteria['is_error']) {
            $query->errors();
        }

        // Apply ordering, limit and offset
        $models = $query
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $models->map(fn (ApiLog $model) => $model->toLogEntry());
    }

    /**
     * {@inheritdoc}
     */
    public function findByRequestId(string $requestId): ?LogEntry
    {
        $model = ApiLog::where('request_id', $requestId)->first();

        return $model?->toLogEntry();
    }

    /**
     * {@inheritdoc}
     */
    public function delete(array $criteria): int
    {
        $query = ApiLog::query();

        // Apply criteria filters
        if (isset($criteria['request_id'])) {
            $query->where('request_id', $criteria['request_id']);
        }

        if (isset($criteria['method'])) {
            $query->where('method', $criteria['method']);
        }

        if (isset($criteria['endpoint'])) {
            $query->where('endpoint', $criteria['endpoint']);
        }

        if (isset($criteria['older_than_days'])) {
            $query->olderThan($criteria['older_than_days']);
        }

        if (isset($criteria['response_code'])) {
            $query->where('response_code', $criteria['response_code']);
        }

        if (isset($criteria['from_date'])) {
            $query->where('created_at', '>=', Carbon::parse($criteria['from_date']));
        }

        if (isset($criteria['to_date'])) {
            $query->where('created_at', '<=', Carbon::parse($criteria['to_date']));
        }

        try {
            return $query->delete();
        } catch (QueryException $e) {
            Log::error('Failed to delete API log entries', [
                'criteria' => $criteria,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteByRequestId(string $requestId): bool
    {
        try {
            $deleted = ApiLog::where('request_id', $requestId)->delete();

            return $deleted > 0;
        } catch (QueryException $e) {
            Log::error('Failed to delete API log entry', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clean(int $normalDays, int $errorDays): int
    {
        $deleted = 0;

        try {
            // Delete normal logs older than specified days
            $normalCutoff = Carbon::now()->subDays($normalDays);
            $deleted += ApiLog::where('response_code', '<', 400)
                ->where('created_at', '<', $normalCutoff)
                ->delete();

            // Delete error logs older than specified days
            $errorCutoff = Carbon::now()->subDays($errorDays);
            $deleted += ApiLog::where('response_code', '>=', 400)
                ->where('created_at', '<', $errorCutoff)
                ->delete();
        } catch (QueryException $e) {
            Log::error('Failed to clean old API log entries', [
                'normal_days' => $normalDays,
                'error_days' => $errorDays,
                'error' => $e->getMessage(),
            ]);
        }

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function count(array $criteria = []): int
    {
        $query = ApiLog::query();

        // Apply criteria filters
        if (isset($criteria['method'])) {
            $query->where('method', $criteria['method']);
        }

        if (isset($criteria['endpoint'])) {
            $query->where('endpoint', $criteria['endpoint']);
        }

        if (isset($criteria['user_identifier'])) {
            $query->where('user_identifier', $criteria['user_identifier']);
        }

        if (isset($criteria['response_code'])) {
            $query->where('response_code', $criteria['response_code']);
        }

        if (isset($criteria['from_date'])) {
            $query->where('created_at', '>=', Carbon::parse($criteria['from_date']));
        }

        if (isset($criteria['to_date'])) {
            $query->where('created_at', '<=', Carbon::parse($criteria['to_date']));
        }

        if (isset($criteria['is_error']) && $criteria['is_error']) {
            $query->errors();
        }

        return $query->count();
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        try {
            $this->connection->getPdo();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStatistics(): array
    {
        try {
            $stats = [
                'total_entries' => ApiLog::count(),
                'total_errors' => ApiLog::errors()->count(),
                'total_success' => ApiLog::successful()->count(),
                'avg_response_time' => ApiLog::avg('response_time_ms'),
                'max_response_time' => ApiLog::max('response_time_ms'),
                'min_response_time' => ApiLog::min('response_time_ms'),
                'storage_type' => 'database',
                'table_name' => $this->table,
                'connection' => $this->connection->getName(),
            ];

            // Get counts by status code groups
            $stats['status_groups'] = [
                '2xx' => ApiLog::whereBetween('response_code', [200, 299])->count(),
                '3xx' => ApiLog::whereBetween('response_code', [300, 399])->count(),
                '4xx' => ApiLog::whereBetween('response_code', [400, 499])->count(),
                '5xx' => ApiLog::where('response_code', '>=', 500)->count(),
            ];

            // Get top endpoints
            $stats['top_endpoints'] = ApiLog::query()
                ->selectRaw('endpoint, method, COUNT(*) as count, AVG(response_time_ms) as avg_time')
                ->groupBy('endpoint', 'method')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->toArray();

            return $stats;
        } catch (\Exception $e) {
            Log::error('Failed to get database storage statistics', [
                'error' => $e->getMessage(),
            ]);

            return [
                'storage_type' => 'database',
                'error' => 'Failed to retrieve statistics',
            ];
        }
    }
}
