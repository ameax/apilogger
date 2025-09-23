<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Jobs;

use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StoreApiLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int>
     */
    public function backoff(): array
    {
        return [1, 5, 10]; // Retry after 1, 5, and 10 seconds
    }

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $logData
     */
    public function __construct(
        protected array $logData
    ) {}

    /**
     * Execute the job.
     */
    public function handle(StorageManager $storageManager): void
    {
        try {
            // Recreate the LogEntry from the array data
            $logEntry = LogEntry::fromArray($this->logData);

            // Get the configured storage driver
            $storage = $storageManager->driver();

            // Store the log entry
            $storage->store($logEntry);
        } catch (\Throwable $exception) {
            // Log the failure
            Log::error('Failed to store API log via queue', [
                'exception' => $exception->getMessage(),
                'log_data' => $this->logData,
                'attempt' => $this->attempts(),
            ]);

            // Check if we should retry
            if ($this->attempts() < $this->tries) {
                // Release the job back to the queue with delay
                $delay = $this->backoff()[$this->attempts() - 1] ?? 10;
                $this->release($delay);
            } else {
                // Final attempt failed, mark as failed
                $this->fail($exception);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        // Log the final failure
        Log::critical('API log storage job permanently failed', [
            'exception' => $exception?->getMessage(),
            'log_data' => $this->logData,
            'attempts' => $this->attempts(),
        ]);

        // Optionally, we could try to store in a fallback location
        // or send an alert to administrators
        $this->attemptFallbackStorage();
    }

    /**
     * Attempt to store the log in a fallback location.
     */
    protected function attemptFallbackStorage(): void
    {
        try {
            // Try to write to a simple log file as a last resort
            $fallbackPath = storage_path('logs/api-logger-failures.log');

            $logLine = json_encode([
                'timestamp' => now()->toIso8601String(),
                'data' => $this->logData,
            ]).PHP_EOL;

            file_put_contents($fallbackPath, $logLine, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // If even the fallback fails, there's nothing more we can do
            Log::emergency('API logger fallback storage also failed', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'api-logger',
            'request-id:'.($this->logData['request_id'] ?? 'unknown'),
        ];
    }

    /**
     * Get the display name for the job.
     */
    public function displayName(): string
    {
        $method = $this->logData['method'] ?? 'UNKNOWN';
        $endpoint = $this->logData['endpoint'] ?? '/unknown';

        return sprintf('Store API Log: %s %s', $method, $endpoint);
    }

    /**
     * Determine if the job should be encrypted.
     */
    public function shouldBeEncrypted(): bool
    {
        // Encrypt jobs that might contain sensitive data
        return true;
    }
}
