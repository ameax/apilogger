<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Commands;

use Ameax\ApiLogger\StorageManager;
use Illuminate\Console\Command;

class CleanApiLogsCommand extends Command
{
    public $signature = 'api-logger:clean
        {--days= : Days to keep normal logs (2xx, 3xx responses)}
        {--error-days= : Days to keep error logs (4xx, 5xx responses)}
        {--force : Skip confirmation prompt}';

    public $description = 'Clean old API log entries based on retention policy';

    public function handle(StorageManager $storageManager): int
    {
        $normalDays = (int) ($this->option('days') ?? config('apilogger.retention.days', 30));
        $errorDays = (int) ($this->option('error-days') ?? config('apilogger.retention.error_days', 90));

        $this->info("Cleaning API logs older than {$normalDays} days (errors: {$errorDays} days)...");

        if (! $this->option('force') && ! $this->confirm('Do you want to proceed with cleaning old logs?')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $storage = $storageManager->store();

        if (! $storage->isAvailable()) {
            $this->error('Storage is not available.');

            return self::FAILURE;
        }

        $deleted = $storage->clean($normalDays, $errorDays);

        $this->info("Successfully deleted {$deleted} old log entries.");

        return self::SUCCESS;
    }
}
