<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Storage;

use Ameax\ApiLogger\Contracts\StorageInterface;
use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class JsonLineStorage implements StorageInterface
{
    protected string $basePath;

    protected string $filenameFormat;

    protected bool $rotateDaily;

    protected bool $compressOldFiles;

    protected int $lockTimeout = 5;

    protected int $lockRetries = 3;

    public function __construct(array $config = [])
    {
        $this->basePath = $config['path'] ?? storage_path('logs/api');
        $this->filenameFormat = $config['filename_format'] ?? 'api-{date}.jsonl';
        $this->rotateDaily = $config['rotate_daily'] ?? true;
        $this->compressOldFiles = $config['compress_old_files'] ?? true;

        // Ensure base path exists
        $this->ensureDirectoryExists($this->basePath);

        // Compress old files if enabled
        if ($this->compressOldFiles) {
            $this->compressOldFiles();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function store(LogEntry $entry): bool
    {
        $filename = $this->getFilename($entry->getCreatedAt());
        $filepath = $this->basePath.DIRECTORY_SEPARATOR.$filename;

        $json = $entry->toJson().PHP_EOL;

        return $this->writeToFile($filepath, $json);
    }

    /**
     * {@inheritdoc}
     */
    public function storeBatch(Collection $entries): int
    {
        if ($entries->isEmpty()) {
            return 0;
        }

        // Group entries by date if daily rotation is enabled
        $grouped = $this->rotateDaily
            ? $entries->groupBy(fn (LogEntry $entry) => $entry->getCreatedAt()->format('Y-m-d'))
            : collect(['all' => $entries]);

        $stored = 0;

        foreach ($grouped as $date => $groupEntries) {
            $createdAt = $date === 'all' ? Carbon::now() : Carbon::parse($date);
            $filename = $this->getFilename($createdAt);
            $filepath = $this->basePath.DIRECTORY_SEPARATOR.$filename;

            // Build batch content
            $content = $groupEntries->map(fn (LogEntry $entry) => $entry->toJson().PHP_EOL)->join('');

            if ($this->writeToFile($filepath, $content)) {
                $stored += $groupEntries->count();
            }
        }

        return $stored;
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(array $criteria = [], int $limit = 100, int $offset = 0): Collection
    {
        $files = $this->getRelevantFiles($criteria);
        $entries = new Collection;

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $handle = @fopen($file, 'r');
            if (! $handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                try {
                    $data = json_decode($line, true);
                    if (! is_array($data)) {
                        continue;
                    }

                    $entry = LogEntry::fromArray($data);

                    // Apply criteria filters
                    if (! $this->matchesCriteria($entry, $criteria)) {
                        continue;
                    }

                    $entries->push($entry);

                    // Stop if we've reached the limit + offset
                    if ($entries->count() >= $limit + $offset) {
                        break 2;
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to parse JSON line in API log file', [
                        'file' => $file,
                        'line' => substr($line, 0, 100),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            fclose($handle);
        }

        // Sort by created_at desc and apply limit/offset
        return $entries
            ->sortByDesc(fn (LogEntry $entry) => $entry->getCreatedAt())
            ->slice($offset, $limit)
            ->values();
    }

    /**
     * {@inheritdoc}
     */
    public function findByRequestId(string $requestId): ?LogEntry
    {
        $files = $this->getAllFiles();

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $handle = @fopen($file, 'r');
            if (! $handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                try {
                    $data = json_decode($line, true);
                    if (! is_array($data) || ! isset($data['request_id'])) {
                        continue;
                    }

                    if ($data['request_id'] === $requestId) {
                        fclose($handle);

                        return LogEntry::fromArray($data);
                    }
                } catch (\Exception) {
                    continue;
                }
            }

            fclose($handle);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(array $criteria): int
    {
        $files = $this->getRelevantFiles($criteria);
        $deleted = 0;

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $tempFile = $file.'.tmp';
            $handle = @fopen($file, 'r');
            $tempHandle = @fopen($tempFile, 'w');

            if (! $handle || ! $tempHandle) {
                continue;
            }

            // Lock the temp file for writing
            if (! flock($tempHandle, LOCK_EX)) {
                fclose($handle);
                fclose($tempHandle);
                @unlink($tempFile);

                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                try {
                    $data = json_decode($line, true);
                    if (! is_array($data)) {
                        fwrite($tempHandle, $line.PHP_EOL);

                        continue;
                    }

                    $entry = LogEntry::fromArray($data);

                    if ($this->matchesCriteria($entry, $criteria)) {
                        $deleted++;
                    } else {
                        fwrite($tempHandle, $line.PHP_EOL);
                    }
                } catch (\Exception) {
                    fwrite($tempHandle, $line.PHP_EOL);
                }
            }

            flock($tempHandle, LOCK_UN);
            fclose($handle);
            fclose($tempHandle);

            // Atomically replace the original file
            @rename($tempFile, $file);
        }

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteByRequestId(string $requestId): bool
    {
        $deleted = $this->delete(['request_id' => $requestId]);

        return $deleted > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function clean(int $normalDays, int $errorDays): int
    {
        $normalCutoff = Carbon::now()->subDays($normalDays);
        $errorCutoff = Carbon::now()->subDays($errorDays);
        $files = $this->getAllFiles();
        $deleted = 0;

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $tempFile = $file.'.tmp';
            $handle = @fopen($file, 'r');
            $tempHandle = @fopen($tempFile, 'w');

            if (! $handle || ! $tempHandle) {
                continue;
            }

            // Lock the temp file for writing
            if (! flock($tempHandle, LOCK_EX)) {
                fclose($handle);
                fclose($tempHandle);
                @unlink($tempFile);

                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                try {
                    $data = json_decode($line, true);
                    if (! is_array($data)) {
                        fwrite($tempHandle, $line.PHP_EOL);

                        continue;
                    }

                    $entry = LogEntry::fromArray($data);
                    $createdAt = $entry->getCreatedAt();
                    $isError = $entry->isError();

                    // Determine if entry should be deleted
                    $shouldDelete = ($isError && $createdAt < $errorCutoff)
                        || (! $isError && $createdAt < $normalCutoff);

                    if ($shouldDelete) {
                        $deleted++;
                    } else {
                        fwrite($tempHandle, $line.PHP_EOL);
                    }
                } catch (\Exception) {
                    fwrite($tempHandle, $line.PHP_EOL);
                }
            }

            flock($tempHandle, LOCK_UN);
            fclose($handle);
            fclose($tempHandle);

            // Atomically replace the original file
            @rename($tempFile, $file);

            // Delete empty files
            if (filesize($file) === 0) {
                @unlink($file);
            }
        }

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function count(array $criteria = []): int
    {
        $files = $this->getRelevantFiles($criteria);
        $count = 0;

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $handle = @fopen($file, 'r');
            if (! $handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                try {
                    $data = json_decode($line, true);
                    if (! is_array($data)) {
                        continue;
                    }

                    $entry = LogEntry::fromArray($data);

                    if ($this->matchesCriteria($entry, $criteria)) {
                        $count++;
                    }
                } catch (\Exception) {
                    continue;
                }
            }

            fclose($handle);
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return is_dir($this->basePath) && is_writable($this->basePath);
    }

    /**
     * {@inheritdoc}
     */
    public function getStatistics(): array
    {
        $files = $this->getAllFiles();
        $stats = [
            'storage_type' => 'jsonline',
            'base_path' => $this->basePath,
            'total_files' => count($files),
            'total_size_bytes' => 0,
            'total_entries' => 0,
            'total_errors' => 0,
            'oldest_file' => null,
            'newest_file' => null,
        ];

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $stats['total_size_bytes'] += filesize($file);

            // Get file dates from filename if using date format
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', basename($file), $matches)) {
                $fileDate = $matches[1];
                if ($stats['oldest_file'] === null || $fileDate < $stats['oldest_file']) {
                    $stats['oldest_file'] = $fileDate;
                }
                if ($stats['newest_file'] === null || $fileDate > $stats['newest_file']) {
                    $stats['newest_file'] = $fileDate;
                }
            }

            // Count entries and errors
            $handle = @fopen($file, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    $stats['total_entries']++;

                    try {
                        $data = json_decode($line, true);
                        if (is_array($data) && isset($data['response_code']) && $data['response_code'] >= 400) {
                            $stats['total_errors']++;
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }
                fclose($handle);
            }
        }

        $stats['total_size_mb'] = round($stats['total_size_bytes'] / 1024 / 1024, 2);
        $stats['compressed_files'] = glob($this->basePath.DIRECTORY_SEPARATOR.'*.jsonl.gz');
        $stats['compressed_count'] = count($stats['compressed_files']);

        return $stats;
    }

    /**
     * Get the filename for a given date.
     */
    protected function getFilename(Carbon $date): string
    {
        if ($this->rotateDaily) {
            return str_replace('{date}', $date->format('Y-m-d'), $this->filenameFormat);
        }

        return str_replace('{date}', 'current', $this->filenameFormat);
    }

    /**
     * Get all log files in the base path.
     *
     * @return array<string>
     */
    protected function getAllFiles(): array
    {
        $pattern = str_replace('{date}', '*', $this->filenameFormat);
        $files = glob($this->basePath.DIRECTORY_SEPARATOR.$pattern);

        return $files ?: [];
    }

    /**
     * Get files that might contain entries matching the criteria.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string>
     */
    protected function getRelevantFiles(array $criteria): array
    {
        $files = $this->getAllFiles();

        // Filter files by date if date criteria is present
        if (isset($criteria['from_date']) || isset($criteria['to_date'])) {
            $fromDate = isset($criteria['from_date']) ? Carbon::parse($criteria['from_date'])->format('Y-m-d') : '1970-01-01';
            $toDate = isset($criteria['to_date']) ? Carbon::parse($criteria['to_date'])->format('Y-m-d') : '2099-12-31';

            $files = array_filter($files, function ($file) use ($fromDate, $toDate) {
                if (preg_match('/(\d{4}-\d{2}-\d{2})/', basename($file), $matches)) {
                    $fileDate = $matches[1];

                    return $fileDate >= $fromDate && $fileDate <= $toDate;
                }

                return true;
            });
        }

        return array_values($files);
    }

    /**
     * Check if a log entry matches the given criteria.
     *
     * @param  array<string, mixed>  $criteria
     */
    protected function matchesCriteria(LogEntry $entry, array $criteria): bool
    {
        if (isset($criteria['request_id']) && $entry->getRequestId() !== $criteria['request_id']) {
            return false;
        }

        if (isset($criteria['method']) && $entry->getMethod() !== $criteria['method']) {
            return false;
        }

        if (isset($criteria['endpoint']) && $entry->getEndpoint() !== $criteria['endpoint']) {
            return false;
        }

        if (isset($criteria['user_identifier']) && $entry->getUserIdentifier() !== $criteria['user_identifier']) {
            return false;
        }

        if (isset($criteria['response_code']) && $entry->getResponseCode() !== $criteria['response_code']) {
            return false;
        }

        if (isset($criteria['from_date']) && $entry->getCreatedAt() < Carbon::parse($criteria['from_date'])) {
            return false;
        }

        if (isset($criteria['to_date']) && $entry->getCreatedAt() > Carbon::parse($criteria['to_date'])) {
            return false;
        }

        if (isset($criteria['min_response_time']) && $entry->getResponseTimeMs() < $criteria['min_response_time']) {
            return false;
        }

        if (isset($criteria['is_error']) && $criteria['is_error'] && ! $entry->isError()) {
            return false;
        }

        if (isset($criteria['older_than_days'])) {
            $cutoff = Carbon::now()->subDays($criteria['older_than_days']);
            if ($entry->getCreatedAt() >= $cutoff) {
                return false;
            }
        }

        return true;
    }

    /**
     * Write content to a file with locking.
     */
    protected function writeToFile(string $filepath, string $content): bool
    {
        $retries = 0;

        while ($retries < $this->lockRetries) {
            $handle = @fopen($filepath, 'a');

            if (! $handle) {
                Log::error('Failed to open JSON line log file for writing', [
                    'file' => $filepath,
                ]);

                return false;
            }

            // Try to acquire exclusive lock
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $result = fwrite($handle, $content);
                flock($handle, LOCK_UN);
                fclose($handle);

                return $result !== false;
            }

            fclose($handle);
            $retries++;

            // Wait a bit before retrying
            usleep(100000); // 100ms
        }

        Log::warning('Failed to acquire lock for JSON line log file after retries', [
            'file' => $filepath,
            'retries' => $this->lockRetries,
        ]);

        return false;
    }

    /**
     * Ensure the directory exists.
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }

    /**
     * Compress old log files.
     */
    protected function compressOldFiles(): void
    {
        if (! function_exists('gzencode')) {
            return;
        }

        $files = $this->getAllFiles();
        $yesterday = Carbon::yesterday()->format('Y-m-d');

        foreach ($files as $file) {
            // Skip if already compressed
            if (str_ends_with($file, '.gz')) {
                continue;
            }

            // Only compress files older than yesterday
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', basename($file), $matches)) {
                $fileDate = $matches[1];

                if ($fileDate < $yesterday) {
                    $this->compressFile($file);
                }
            }
        }
    }

    /**
     * Compress a single file.
     */
    protected function compressFile(string $file): void
    {
        $content = @file_get_contents($file);

        if ($content === false) {
            return;
        }

        $compressed = @gzencode($content, 9);

        if ($compressed === false) {
            return;
        }

        $gzFile = $file.'.gz';

        if (@file_put_contents($gzFile, $compressed) !== false) {
            @unlink($file);
        }
    }
}
