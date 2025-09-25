<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Outbound;

use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\RequestInterface;

class OutboundFilterService
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function shouldLog(
        RequestInterface $request,
        ?string $serviceClass = null,
        array $metadata = []
    ): bool {
        if (! $this->isOutboundLoggingEnabled()) {
            return false;
        }

        $cacheKey = $this->getCacheKey($request, $serviceClass);

        if ($this->shouldUseCache()) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $shouldLog = $this->evaluateFilters($request, $serviceClass, $metadata);

        if ($this->shouldUseCache()) {
            Cache::put($cacheKey, $shouldLog, $this->getCacheTtl());
        }

        return $shouldLog;
    }

    private function evaluateFilters(
        RequestInterface $request,
        ?string $serviceClass,
        array $metadata
    ): bool {
        // Check exclude filters first (they have priority)
        if ($this->isExcluded($request, $serviceClass, $metadata)) {
            return false;
        }

        // If no include filters are defined, log everything not excluded
        if (! $this->hasIncludeFilters()) {
            return true;
        }

        // Check include filters
        return $this->isIncluded($request, $serviceClass, $metadata);
    }

    private function isExcluded(
        RequestInterface $request,
        ?string $serviceClass,
        array $metadata
    ): bool {
        $host = $request->getUri()->getHost();
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // Check excluded hosts
        if ($this->matchesPatterns($host, $this->config['features']['outbound']['filters']['exclude_hosts'] ?? [])) {
            return true;
        }

        // Check excluded services
        if ($serviceClass && in_array($serviceClass, $this->config['features']['outbound']['filters']['exclude_services'] ?? [])) {
            return true;
        }

        // Check excluded URL patterns
        if ($this->matchesPatterns($path, $this->config['features']['outbound']['filters']['exclude_patterns'] ?? [])) {
            return true;
        }

        // Check excluded methods
        if (in_array(strtoupper($method), array_map('strtoupper', $this->config['features']['outbound']['filters']['exclude_methods'] ?? []))) {
            return true;
        }

        // Check custom exclude callback
        $excludeCallback = $this->config['features']['outbound']['filters']['exclude_callback'] ?? null;
        if (is_callable($excludeCallback) && $excludeCallback($request, $serviceClass, $metadata)) {
            return true;
        }

        return false;
    }

    private function isIncluded(
        RequestInterface $request,
        ?string $serviceClass,
        array $metadata
    ): bool {
        $host = $request->getUri()->getHost();
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // Check included hosts
        $includeHosts = $this->config['features']['outbound']['filters']['include_hosts'] ?? [];
        if (! empty($includeHosts) && ! $this->matchesPatterns($host, $includeHosts)) {
            return false;
        }

        // Check included services
        $includeServices = $this->config['features']['outbound']['filters']['include_services'] ?? [];
        if (! empty($includeServices) && $serviceClass && ! in_array($serviceClass, $includeServices)) {
            return false;
        }

        // Check included URL patterns
        $includePatterns = $this->config['features']['outbound']['filters']['include_patterns'] ?? [];
        if (! empty($includePatterns) && ! $this->matchesPatterns($path, $includePatterns)) {
            return false;
        }

        // Check included methods
        $includeMethods = $this->config['features']['outbound']['filters']['include_methods'] ?? [];
        if (! empty($includeMethods) && ! in_array(strtoupper($method), array_map('strtoupper', $includeMethods))) {
            return false;
        }

        // Check custom include callback
        $includeCallback = $this->config['features']['outbound']['filters']['include_callback'] ?? null;
        if (is_callable($includeCallback)) {
            return $includeCallback($request, $serviceClass, $metadata);
        }

        return true;
    }

    private function matchesPatterns(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($value, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        // Check if it's a regex pattern (starts and ends with /)
        if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
            return (bool) preg_match($pattern, $value);
        }

        // Convert wildcard pattern to regex
        $regex = '/^'.str_replace(
            ['\\*', '\\?'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ).'$/i';

        return (bool) preg_match($regex, $value);
    }

    private function hasIncludeFilters(): bool
    {
        $filters = $this->config['features']['outbound']['filters'] ?? [];

        return ! empty($filters['include_hosts']) ||
               ! empty($filters['include_services']) ||
               ! empty($filters['include_patterns']) ||
               ! empty($filters['include_methods']) ||
               isset($filters['include_callback']);
    }

    private function isOutboundLoggingEnabled(): bool
    {
        return $this->config['features']['outbound']['enabled'] ?? false;
    }

    private function shouldUseCache(): bool
    {
        $env = $this->config['app']['env'] ?? 'production';

        return $env === 'production' && ($this->config['features']['outbound']['filters']['cache_enabled'] ?? true);
    }

    private function getCacheTtl(): int
    {
        return $this->config['features']['outbound']['filters']['cache_ttl'] ?? 60;
    }

    private function getCacheKey(RequestInterface $request, ?string $serviceClass): string
    {
        return 'apilogger:outbound:filter:'.md5(
            $request->getUri()->__toString().
            '|'.$request->getMethod().
            '|'.($serviceClass ?? '')
        );
    }

    public function getServiceConfig(string $serviceClass): array
    {
        $globalConfig = $this->config['features']['outbound'] ?? [];
        $serviceConfig = $this->config['features']['outbound']['services']['configs'][$serviceClass] ?? [];

        // Merge service-specific config with global config (service-specific overrides)
        return array_merge($globalConfig, $serviceConfig);
    }

    public function shouldLogErrors(string $serviceClass): bool
    {
        $config = $this->getServiceConfig($serviceClass);

        return $config['always_log_errors'] ?? true;
    }
}
