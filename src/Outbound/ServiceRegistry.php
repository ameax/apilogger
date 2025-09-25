<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Outbound;

use InvalidArgumentException;

class ServiceRegistry
{
    private static array $services = [];

    private static array $metadata = [];

    public static function register(
        string $serviceClass,
        array $config = [],
        array $metadata = []
    ): void {
        if (! class_exists($serviceClass)) {
            throw new InvalidArgumentException("Service class {$serviceClass} does not exist");
        }

        self::$services[$serviceClass] = $config;
        self::$metadata[$serviceClass] = array_merge(
            $metadata,
            [
                'registered_at' => now()->toIso8601String(),
                'class' => $serviceClass,
            ]
        );
    }

    public static function unregister(string $serviceClass): void
    {
        unset(self::$services[$serviceClass]);
        unset(self::$metadata[$serviceClass]);
    }

    public static function isRegistered(string $serviceClass): bool
    {
        return isset(self::$services[$serviceClass]);
    }

    public static function getConfig(string $serviceClass): ?array
    {
        return self::$services[$serviceClass] ?? null;
    }

    public static function getMetadata(string $serviceClass): ?array
    {
        return self::$metadata[$serviceClass] ?? null;
    }

    public static function getAllServices(): array
    {
        return array_keys(self::$services);
    }

    public static function getAllConfigs(): array
    {
        return self::$services;
    }

    public static function shouldLog(string $serviceClass): bool
    {
        if (! self::isRegistered($serviceClass)) {
            return false;
        }

        $config = self::getConfig($serviceClass);

        return $config['enabled'] ?? true;
    }

    public static function getServiceName(string $serviceClass): string
    {
        $config = self::getConfig($serviceClass);
        if (isset($config['name'])) {
            return $config['name'];
        }

        $metadata = self::getMetadata($serviceClass);
        if (isset($metadata['name'])) {
            return $metadata['name'];
        }

        // Extract simple class name as fallback
        $parts = explode('\\', $serviceClass);

        return end($parts);
    }

    public static function getServiceHosts(string $serviceClass): array
    {
        $config = self::getConfig($serviceClass);

        return $config['hosts'] ?? [];
    }

    public static function findServiceByHost(string $host): ?string
    {
        foreach (self::$services as $serviceClass => $config) {
            $hosts = $config['hosts'] ?? [];
            foreach ($hosts as $pattern) {
                if (self::hostMatches($host, $pattern)) {
                    return $serviceClass;
                }
            }
        }

        return null;
    }

    private static function hostMatches(string $host, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $regex = '/^'.str_replace(
            ['\\*', '\\?'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ).'$/i';

        return (bool) preg_match($regex, $host);
    }

    public static function getLogLevel(string $serviceClass): string
    {
        $config = self::getConfig($serviceClass);

        return $config['log_level'] ?? 'full';
    }

    public static function getSanitizeFields(string $serviceClass): array
    {
        $config = self::getConfig($serviceClass);

        return $config['sanitize_fields'] ?? [];
    }

    public static function getTimeout(string $serviceClass): ?int
    {
        $config = self::getConfig($serviceClass);

        return isset($config['timeout_ms']) ? (int) $config['timeout_ms'] : null;
    }

    public static function shouldAlwaysLogErrors(string $serviceClass): bool
    {
        $config = self::getConfig($serviceClass);

        return $config['always_log_errors'] ?? true;
    }

    public static function mergeWithGlobalConfig(string $serviceClass, array $globalConfig): array
    {
        $serviceConfig = self::getConfig($serviceClass) ?? [];

        // Service-specific config overrides global config
        return array_replace_recursive($globalConfig, $serviceConfig);
    }

    public static function clear(): void
    {
        self::$services = [];
        self::$metadata = [];
    }

    public static function count(): int
    {
        return count(self::$services);
    }

    public static function toArray(): array
    {
        return [
            'services' => self::$services,
            'metadata' => self::$metadata,
        ];
    }

    public static function fromArray(array $data): void
    {
        self::$services = $data['services'] ?? [];
        self::$metadata = $data['metadata'] ?? [];
    }
}
