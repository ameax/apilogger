<?php

declare(strict_types=1);

namespace Ameax\ApiLogger;

use Ameax\ApiLogger\Contracts\StorageInterface;
use Ameax\ApiLogger\DataTransferObjects\LogEntry;

class ApiLogger
{
    /**
     * Create a new ApiLogger instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = [],
        protected ?StorageInterface $storage = null,
    ) {}

    /**
     * Check if logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    /**
     * Get the current logging level.
     */
    public function getLevel(): string
    {
        return $this->config['level'] ?? 'detailed';
    }

    /**
     * Set the storage implementation.
     */
    public function setStorage(StorageInterface $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    /**
     * Get the storage implementation.
     */
    public function getStorage(): ?StorageInterface
    {
        return $this->storage;
    }

    /**
     * Log an API request/response.
     *
     * This method will be fully implemented in Phase 3 with middleware integration.
     */
    public function log(LogEntry $entry): bool
    {
        if (! $this->isEnabled() || $this->getLevel() === 'none') {
            return false;
        }

        if ($this->storage === null) {
            return false;
        }

        // Apply filters (to be implemented in Phase 3)
        if (! $this->shouldLog($entry)) {
            return false;
        }

        // Store the log entry
        return $this->storage->store($entry);
    }

    /**
     * Determine if a log entry should be logged based on filters.
     *
     * This will be fully implemented in Phase 3.
     */
    protected function shouldLog(LogEntry $entry): bool
    {
        // Placeholder for filter logic
        // Will check include/exclude routes, methods, status codes, etc.
        return true;
    }

    /**
     * Get configuration value.
     *
     * @param  mixed  $default
     * @return mixed
     */
    public function config(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }
}
