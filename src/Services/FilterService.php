<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Services;

use Illuminate\Http\Request;

class FilterService
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config['filters'] ?? [];
    }

    /**
     * Check if a request should be logged based on all configured filters.
     */
    public function shouldLog(Request $request, $response, float $responseTime): bool
    {

        // Check route filters
        if (! $this->passesRouteFilter($request)) {
            return false;
        }

        // Check method filters
        if (! $this->passesMethodFilter($request)) {
            return false;
        }

        // Check status code filters
        if (! $this->passesStatusCodeFilter($response)) {
            return false;
        }

        // Check response time threshold
        if (! $this->passesResponseTimeFilter($responseTime)) {
            return false;
        }

        // Check custom filters
        if (! $this->passesCustomFilters($request, $response)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the request passes route filters.
     */
    protected function passesRouteFilter(Request $request): bool
    {
        $path = $request->path();

        // Normalize path to start with /
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        // Check exclude routes first (takes precedence)
        $excludeRoutes = $this->config['exclude_routes'] ?? [];
        if ($this->matchesRoutePatterns($path, $excludeRoutes)) {
            return false;
        }

        // Check include routes (if specified, only these routes are logged)
        $includeRoutes = $this->config['include_routes'] ?? [];
        if (! empty($includeRoutes)) {
            return $this->matchesRoutePatterns($path, $includeRoutes);
        }

        // If no include routes specified, log everything not excluded
        return true;
    }

    /**
     * Check if the request method passes filters.
     */
    protected function passesMethodFilter(Request $request): bool
    {
        $method = $request->getMethod();

        // Check exclude methods first
        $excludeMethods = array_map('strtoupper', $this->config['exclude_methods'] ?? []);
        if (in_array($method, $excludeMethods, true)) {
            return false;
        }

        // Check include methods (if specified, only these methods are logged)
        $includeMethods = array_map('strtoupper', $this->config['include_methods'] ?? []);
        if (! empty($includeMethods)) {
            return in_array($method, $includeMethods, true);
        }

        return true;
    }

    /**
     * Check if the response status code passes filters.
     */
    protected function passesStatusCodeFilter($response): bool
    {
        $statusCode = $response->getStatusCode();

        // Check exclude status codes first
        $excludeCodes = $this->config['exclude_status_codes'] ?? [];
        if (in_array($statusCode, $excludeCodes, true)) {
            return false;
        }

        // Check include status codes (if specified, only these codes are logged)
        $includeCodes = $this->config['include_status_codes'] ?? [];
        if (! empty($includeCodes)) {
            return in_array($statusCode, $includeCodes, true);
        }

        return true;
    }

    /**
     * Check if the response time passes the minimum threshold filter.
     */
    protected function passesResponseTimeFilter(float $responseTimeMs): bool
    {
        $minResponseTime = $this->config['min_response_time'] ?? 0;

        if ($minResponseTime <= 0) {
            return true;
        }

        return $responseTimeMs >= $minResponseTime;
    }

    /**
     * Check if a path matches any of the given route patterns.
     *
     * @param  array<string>  $patterns
     */
    protected function matchesRoutePatterns(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a path matches a specific pattern.
     * Supports wildcards (*) and double wildcards (**).
     */
    protected function matchesPattern(string $path, string $pattern): bool
    {
        // Normalize pattern
        if (! str_starts_with($pattern, '/')) {
            $pattern = '/'.$pattern;
        }

        // Exact match
        if ($path === $pattern) {
            return true;
        }

        // Check if pattern contains wildcards
        if (! str_contains($pattern, '*')) {
            // No wildcards, check for exact match or prefix match
            return $path === $pattern || str_starts_with($path, $pattern.'/');
        }

        // Convert wildcard pattern to regex
        $regex = $this->patternToRegex($pattern);

        return (bool) preg_match($regex, $path);
    }

    /**
     * Convert a wildcard pattern to a regex pattern.
     */
    protected function patternToRegex(string $pattern): string
    {
        // Escape special regex characters except *
        $pattern = preg_quote($pattern, '#');

        // Replace escaped asterisks with regex equivalents
        $pattern = str_replace('\*\*', '.*', $pattern); // ** matches any number of segments
        $pattern = str_replace('\*', '[^/]*', $pattern); // * matches within a segment

        return '#^'.$pattern.'$#';
    }

    /**
     * Check if a request/response combination matches any custom filter callbacks.
     *
     * @param  \Closure[]  $callbacks
     */
    public function passesCustomFilters(Request $request, $response, array $callbacks = []): bool
    {
        // Get custom callbacks from config if not provided
        if (empty($callbacks)) {
            $callbacks = $this->config['custom_filters'] ?? [];
        }

        foreach ($callbacks as $callback) {
            if (is_callable($callback)) {
                $result = $callback($request, $response);
                if ($result === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Add a custom filter callback.
     */
    public function addCustomFilter(\Closure $callback): void
    {
        if (! isset($this->config['custom_filters'])) {
            $this->config['custom_filters'] = [];
        }

        $this->config['custom_filters'][] = $callback;
    }

    /**
     * Set include routes.
     *
     * @param  array<string>  $routes
     */
    public function includeRoutes(array $routes): void
    {
        $this->config['include_routes'] = $routes;
    }

    /**
     * Set exclude routes.
     *
     * @param  array<string>  $routes
     */
    public function excludeRoutes(array $routes): void
    {
        $this->config['exclude_routes'] = $routes;
    }

    /**
     * Set include methods.
     *
     * @param  array<string>  $methods
     */
    public function includeMethods(array $methods): void
    {
        $this->config['include_methods'] = array_map('strtoupper', $methods);
    }

    /**
     * Set exclude methods.
     *
     * @param  array<string>  $methods
     */
    public function excludeMethods(array $methods): void
    {
        $this->config['exclude_methods'] = array_map('strtoupper', $methods);
    }

    /**
     * Set include status codes.
     *
     * @param  array<int>  $codes
     */
    public function includeStatusCodes(array $codes): void
    {
        $this->config['include_status_codes'] = $codes;
    }

    /**
     * Set exclude status codes.
     *
     * @param  array<int>  $codes
     */
    public function excludeStatusCodes(array $codes): void
    {
        $this->config['exclude_status_codes'] = $codes;
    }

    /**
     * Set minimum response time threshold.
     */
    public function setMinResponseTime(float $milliseconds): void
    {
        $this->config['min_response_time'] = $milliseconds;
    }

    /**
     * Check if errors should always be logged regardless of filters.
     */
    public function shouldAlwaysLogErrors(): bool
    {
        return $this->config['always_log_errors'] ?? true;
    }

    /**
     * Get the current filter configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->config;
    }
}
