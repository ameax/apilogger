<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Contracts;

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Illuminate\Support\Collection;

interface StorageInterface
{
    /**
     * Store a single log entry.
     *
     * @param  LogEntry  $entry  The log entry to store
     * @return bool True if stored successfully
     */
    public function store(LogEntry $entry): bool;

    /**
     * Store multiple log entries in batch.
     *
     * @param  Collection<LogEntry>  $entries  Collection of log entries
     * @return int Number of entries successfully stored
     */
    public function storeBatch(Collection $entries): int;

    /**
     * Retrieve log entries based on criteria.
     *
     * @param  array<string, mixed>  $criteria  Search criteria
     * @param  int  $limit  Maximum number of entries to retrieve
     * @param  int  $offset  Number of entries to skip
     * @return Collection<LogEntry> Collection of matching log entries
     */
    public function retrieve(array $criteria = [], int $limit = 100, int $offset = 0): Collection;

    /**
     * Retrieve a single log entry by request ID.
     *
     * @param  string  $requestId  The unique request identifier
     * @return LogEntry|null The log entry if found
     */
    public function findByRequestId(string $requestId): ?LogEntry;

    /**
     * Delete log entries based on criteria.
     *
     * @param  array<string, mixed>  $criteria  Deletion criteria
     * @return int Number of entries deleted
     */
    public function delete(array $criteria): int;

    /**
     * Delete a single log entry by request ID.
     *
     * @param  string  $requestId  The unique request identifier
     * @return bool True if deleted successfully
     */
    public function deleteByRequestId(string $requestId): bool;

    /**
     * Clean old log entries based on retention policy.
     *
     * @param  int  $normalDays  Days to keep normal logs (2xx, 3xx)
     * @param  int  $errorDays  Days to keep error logs (4xx, 5xx)
     * @return int Number of entries cleaned
     */
    public function clean(int $normalDays, int $errorDays): int;

    /**
     * Get the total count of log entries matching criteria.
     *
     * @param  array<string, mixed>  $criteria  Count criteria
     * @return int Total count of matching entries
     */
    public function count(array $criteria = []): int;

    /**
     * Check if the storage is available and writable.
     *
     * @return bool True if storage is available
     */
    public function isAvailable(): bool;

    /**
     * Get storage statistics.
     *
     * @return array<string, mixed> Statistics about the storage
     */
    public function getStatistics(): array;
}
