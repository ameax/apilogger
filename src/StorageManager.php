<?php

declare(strict_types=1);

namespace Ameax\ApiLogger;

use Ameax\ApiLogger\Contracts\StorageInterface;
use Ameax\ApiLogger\Storage\DatabaseStorage;
use Ameax\ApiLogger\Storage\JsonLineStorage;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;

class StorageManager extends Manager
{
    /**
     * The registered custom driver creators.
     *
     * @var array<string, \Closure>
     */
    protected $customCreators = [];

    /**
     * The active storage instances.
     *
     * @var array<string, StorageInterface>
     */
    protected $stores = [];

    /**
     * Create a new Storage manager instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Get a storage instance by name.
     *
     * @param  string|null  $name
     */
    public function store($name = null): StorageInterface
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->stores[$name] ??= $this->resolve($name);
    }

    /**
     * Resolve the given store.
     *
     * @param  string  $name
     *
     * @throws \InvalidArgumentException
     */
    protected function resolve($name): StorageInterface
    {
        // Check for custom creators first
        if (isset($this->customCreators[$name])) {
            return $this->callCustomCreator($name);
        }

        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new \InvalidArgumentException("Storage [{$name}] is not defined.");
        }

        $driverMethod = 'create'.ucfirst($name).'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new \InvalidArgumentException("Driver [{$name}] is not supported.");
    }

    /**
     * Call a custom driver creator.
     *
     * @param  string  $driver
     * @return mixed
     */
    protected function callCustomCreator($driver)
    {
        $config = $this->getConfig($driver) ?? [];

        return $this->customCreators[$driver]($this->container, $config);
    }

    /**
     * Create an instance of the database storage driver.
     *
     * @param  array<string, mixed>  $config
     */
    protected function createDatabaseDriver(array $config): StorageInterface
    {
        return new DatabaseStorage(
            $this->container->make('db'),
            array_merge(
                $config,
                ['batch_size' => config('apilogger.performance.batch_size', 100)]
            )
        );
    }

    /**
     * Create an instance of the JSON Lines storage driver.
     *
     * @param  array<string, mixed>  $config
     */
    protected function createJsonlineDriver(array $config): StorageInterface
    {
        return new JsonLineStorage($config);
    }

    /**
     * Get the storage connection configuration.
     *
     * @param  string  $name
     * @return array<string, mixed>|null
     */
    protected function getConfig($name): ?array
    {
        if ($name === 'database') {
            return config('apilogger.storage.database');
        }

        if ($name === 'jsonline') {
            return config('apilogger.storage.jsonline');
        }

        return null;
    }

    /**
     * Get the default storage driver name.
     */
    public function getDefaultDriver(): string
    {
        return config('apilogger.storage.driver', 'database');
    }

    /**
     * Set the default storage driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        config(['apilogger.storage.driver' => $name]);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string  $driver
     * @return $this
     */
    public function extend($driver, \Closure $callback)
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Get all of the created "stores".
     *
     * @return array<string, StorageInterface>
     */
    public function getStores(): array
    {
        return $this->stores;
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->store()->$method(...$parameters);
    }

    /**
     * Create a fallback storage that tries multiple drivers.
     *
     * @param  array<string>  $drivers  List of driver names to try in order
     * @param  bool  $stopOnSuccess  If true, stop after first successful write
     */
    public function createFallbackStorage(array $drivers = ['database', 'jsonline'], bool $stopOnSuccess = true): StorageInterface
    {
        return new class($this, $drivers, $stopOnSuccess) implements StorageInterface
        {
            public function __construct(
                private StorageManager $manager,
                private array $drivers,
                private bool $stopOnSuccess
            ) {}

            public function store(\Ameax\ApiLogger\DataTransferObjects\LogEntry $entry): bool
            {
                $success = false;

                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        if ($driverInstance->isAvailable() && $driverInstance->store($entry)) {
                            $success = true;
                            if ($this->stopOnSuccess) {
                                return true;
                            }
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }

                return $success;
            }

            public function storeBatch(\Illuminate\Support\Collection $entries): int
            {
                $maxStored = 0;

                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        if ($driverInstance->isAvailable()) {
                            $stored = $driverInstance->storeBatch($entries);
                            $maxStored = max($maxStored, $stored);
                            if ($this->stopOnSuccess && $stored > 0) {
                                return $stored;
                            }
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }

                return $maxStored;
            }

            public function retrieve(array $criteria = [], int $limit = 100, int $offset = 0): \Illuminate\Support\Collection
            {
                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        if ($driverInstance->isAvailable()) {
                            return $driverInstance->retrieve($criteria, $limit, $offset);
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }

                return new \Illuminate\Support\Collection;
            }

            public function findByRequestId(string $requestId): ?\Ameax\ApiLogger\DataTransferObjects\LogEntry
            {
                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        if ($driverInstance->isAvailable()) {
                            $entry = $driverInstance->findByRequestId($requestId);
                            if ($entry !== null) {
                                return $entry;
                            }
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }

                return null;
            }

            public function delete(array $criteria): int
            {
                $totalDeleted = 0;

                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        if ($driverInstance->isAvailable()) {
                            $totalDeleted += $driverInstance->delete($criteria);
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }

                return $totalDeleted;
            }

            public function deleteByRequestId(string $requestId): bool
            {
                $success = false;

                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        if ($driverInstance->isAvailable() && $driverInstance->deleteByRequestId($requestId)) {
                            $success = true;
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }

                return $success;
            }

            public function clean(int $normalDays, int $errorDays): int
            {
                $totalCleaned = 0;

                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        if ($driverInstance->isAvailable()) {
                            $totalCleaned += $driverInstance->clean($normalDays, $errorDays);
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }

                return $totalCleaned;
            }

            public function count(array $criteria = []): int
            {
                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        if ($driverInstance->isAvailable()) {
                            return $driverInstance->count($criteria);
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }

                return 0;
            }

            public function isAvailable(): bool
            {
                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        if ($driverInstance->isAvailable()) {
                            return true;
                        }
                    } catch (\Exception) {
                        continue;
                    }
                }

                return false;
            }

            public function getStatistics(): array
            {
                $stats = [
                    'storage_type' => 'fallback',
                    'drivers' => [],
                ];

                foreach ($this->drivers as $driver) {
                    try {
                        $driverInstance = $this->manager->store($driver);
                        $stats['drivers'][$driver] = [
                            'available' => $driverInstance->isAvailable(),
                            'statistics' => $driverInstance->isAvailable() ? $driverInstance->getStatistics() : null,
                        ];
                    } catch (\Exception $e) {
                        $stats['drivers'][$driver] = [
                            'available' => false,
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                return $stats;
            }
        };
    }
}
