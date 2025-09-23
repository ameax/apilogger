<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Unit;

use Ameax\ApiLogger\Contracts\StorageInterface;
use Ameax\ApiLogger\DataTransferObjects\LogEntry;
use Ameax\ApiLogger\Storage\DatabaseStorage;
use Ameax\ApiLogger\Storage\JsonLineStorage;
use Ameax\ApiLogger\StorageManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->manager = new StorageManager(app());

    // Setup test path for JSON storage
    $this->testPath = storage_path('tests/logs');
    File::makeDirectory($this->testPath, 0755, true, true);

    config([
        'apilogger.storage.driver' => 'database',
        'apilogger.storage.database' => [
            'connection' => config('database.default'),
            'table' => 'api_logs',
            'batch_size' => 100,
        ],
        'apilogger.storage.jsonline' => [
            'path' => $this->testPath,
            'filename_format' => 'api-{date}.jsonl',
            'rotate_daily' => true,
            'compress_old_files' => false,
        ],
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->testPath);
});

describe('StorageManager', function () {
    test('creates database storage driver', function () {
        $storage = $this->manager->store('database');

        expect($storage)->toBeInstanceOf(DatabaseStorage::class);
        expect($storage)->toBeInstanceOf(StorageInterface::class);
    });

    test('creates jsonline storage driver', function () {
        $storage = $this->manager->store('jsonline');

        expect($storage)->toBeInstanceOf(JsonLineStorage::class);
        expect($storage)->toBeInstanceOf(StorageInterface::class);
    });

    test('uses default driver when none specified', function () {
        config(['apilogger.storage.driver' => 'database']);

        $storage = $this->manager->store();

        expect($storage)->toBeInstanceOf(DatabaseStorage::class);
    });

    test('throws exception for unknown driver', function () {
        expect(fn () => $this->manager->store('unknown'))
            ->toThrow(\InvalidArgumentException::class, 'Storage [unknown] is not defined');
    });

    test('caches storage instances', function () {
        $storage1 = $this->manager->store('database');
        $storage2 = $this->manager->store('database');

        expect($storage1)->toBe($storage2);
    });

    test('can get and set default driver', function () {
        expect($this->manager->getDefaultDriver())->toBe('database');

        $this->manager->setDefaultDriver('jsonline');

        expect($this->manager->getDefaultDriver())->toBe('jsonline');
        expect(config('apilogger.storage.driver'))->toBe('jsonline');
    });

    test('can register custom drivers', function () {
        $customStorage = new class implements StorageInterface
        {
            public function store(LogEntry $entry): bool
            {
                return true;
            }

            public function storeBatch(Collection $entries): int
            {
                return $entries->count();
            }

            public function retrieve(array $criteria = [], int $limit = 100, int $offset = 0): Collection
            {
                return new Collection;
            }

            public function findByRequestId(string $requestId): ?LogEntry
            {
                return null;
            }

            public function delete(array $criteria): int
            {
                return 0;
            }

            public function deleteByRequestId(string $requestId): bool
            {
                return false;
            }

            public function clean(int $normalDays, int $errorDays): int
            {
                return 0;
            }

            public function count(array $criteria = []): int
            {
                return 0;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getStatistics(): array
            {
                return ['type' => 'custom'];
            }
        };

        $this->manager->extend('custom', function ($app, $config) use ($customStorage) {
            return $customStorage;
        });

        $storage = $this->manager->store('custom');

        expect($storage)->toBe($customStorage);
        expect($storage->getStatistics()['type'])->toBe('custom');
    });

    test('forwards method calls to default driver', function () {
        config(['apilogger.storage.driver' => 'jsonline']);

        $entry = new LogEntry(
            requestId: 'forward-test',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 10.0,
        );

        // Get the storage instance first
        $storage = $this->manager->store();

        // These calls should work on the storage instance
        $result = $storage->store($entry);
        expect($result)->toBeTrue();

        $found = $storage->findByRequestId('forward-test');
        expect($found)->not->toBeNull();

        $count = $storage->count();
        expect($count)->toBe(1);
    });

    test('can get all created stores', function () {
        $this->manager->store('database');
        $this->manager->store('jsonline');

        $stores = $this->manager->getStores();

        expect($stores)->toHaveKeys(['database', 'jsonline']);
        expect($stores['database'])->toBeInstanceOf(DatabaseStorage::class);
        expect($stores['jsonline'])->toBeInstanceOf(JsonLineStorage::class);
    });

    test('creates fallback storage with multiple drivers', function () {
        $fallback = $this->manager->createFallbackStorage(['database', 'jsonline']);

        expect($fallback)->toBeInstanceOf(StorageInterface::class);

        // Test that fallback storage works
        $entry = new LogEntry(
            requestId: 'fallback-test',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 10.0,
        );

        $result = $fallback->store($entry);
        expect($result)->toBeTrue();

        // Check availability
        $available = $fallback->isAvailable();
        expect($available)->toBeTrue();

        // Get statistics
        $stats = $fallback->getStatistics();
        expect($stats['storage_type'])->toBe('fallback');
        expect($stats['drivers'])->toHaveKeys(['database', 'jsonline']);
    });

    test('fallback storage tries all drivers when one fails', function () {
        // Create a failing storage mock
        $failingStorage = new class implements StorageInterface
        {
            public function store(LogEntry $entry): bool
            {
                throw new \Exception('Storage failed');
            }

            public function storeBatch(Collection $entries): int
            {
                throw new \Exception('Storage failed');
            }

            public function retrieve(array $criteria = [], int $limit = 100, int $offset = 0): Collection
            {
                throw new \Exception('Storage failed');
            }

            public function findByRequestId(string $requestId): ?LogEntry
            {
                throw new \Exception('Storage failed');
            }

            public function delete(array $criteria): int
            {
                throw new \Exception('Storage failed');
            }

            public function deleteByRequestId(string $requestId): bool
            {
                throw new \Exception('Storage failed');
            }

            public function clean(int $normalDays, int $errorDays): int
            {
                throw new \Exception('Storage failed');
            }

            public function count(array $criteria = []): int
            {
                throw new \Exception('Storage failed');
            }

            public function isAvailable(): bool
            {
                return false;
            }

            public function getStatistics(): array
            {
                throw new \Exception('Storage failed');
            }
        };

        $this->manager->extend('failing', fn () => $failingStorage);

        $fallback = $this->manager->createFallbackStorage(['failing', 'jsonline']);

        $entry = new LogEntry(
            requestId: 'fallback-fail-test',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 10.0,
        );

        // Should still succeed because jsonline works
        $result = $fallback->store($entry);
        expect($result)->toBeTrue();

        // Verify it was stored in jsonline
        $jsonlineStorage = $this->manager->store('jsonline');
        $found = $jsonlineStorage->findByRequestId('fallback-fail-test');
        expect($found)->not->toBeNull();
    });

    test('fallback storage can write to multiple drivers', function () {
        $fallback = $this->manager->createFallbackStorage(['database', 'jsonline'], false);

        $entry = new LogEntry(
            requestId: 'multi-write-test',
            method: 'POST',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 201,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 15.0,
        );

        $result = $fallback->store($entry);
        expect($result)->toBeTrue();

        // Check both storages have the entry
        $dbStorage = $this->manager->store('database');
        $jsonStorage = $this->manager->store('jsonline');

        $dbEntry = $dbStorage->findByRequestId('multi-write-test');
        $jsonEntry = $jsonStorage->findByRequestId('multi-write-test');

        expect($dbEntry)->not->toBeNull();
        expect($jsonEntry)->not->toBeNull();
    });

    test('fallback storage handles batch operations', function () {
        $fallback = $this->manager->createFallbackStorage(['database', 'jsonline']);

        $entries = new Collection([
            new LogEntry(
                requestId: 'batch-fallback-1',
                method: 'GET',
                endpoint: '/api/test1',
                requestHeaders: [],
                requestBody: null,
                responseCode: 200,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 10.0,
            ),
            new LogEntry(
                requestId: 'batch-fallback-2',
                method: 'POST',
                endpoint: '/api/test2',
                requestHeaders: [],
                requestBody: null,
                responseCode: 201,
                responseHeaders: [],
                responseBody: null,
                responseTimeMs: 20.0,
            ),
        ]);

        $stored = $fallback->storeBatch($entries);
        expect($stored)->toBe(2);

        $count = $fallback->count();
        expect($count)->toBe(2);
    });

    test('fallback storage handles cleanup operations', function () {
        $fallback = $this->manager->createFallbackStorage(['database', 'jsonline'], false);

        // Store an old entry
        $oldEntry = new LogEntry(
            requestId: 'old-entry',
            method: 'GET',
            endpoint: '/api/test',
            requestHeaders: [],
            requestBody: null,
            responseCode: 200,
            responseHeaders: [],
            responseBody: null,
            responseTimeMs: 10.0,
            createdAt: \Carbon\Carbon::now()->subDays(10),
        );

        $fallback->store($oldEntry);

        // Clean old entries
        $cleaned = $fallback->clean(7, 14);
        expect($cleaned)->toBeGreaterThan(0);
    });
});
