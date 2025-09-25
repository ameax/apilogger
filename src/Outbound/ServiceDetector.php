<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Outbound;

class ServiceDetector
{
    /**
     * Detect the service class from the current execution context.
     */
    public function detect(array $options = []): ?string
    {
        // Check if service name is provided in options
        if (isset($options['service_name'])) {
            // Try to find registered service by name
            foreach (ServiceRegistry::getAllServices() as $serviceClass) {
                if (ServiceRegistry::getServiceName($serviceClass) === $options['service_name']) {
                    return $serviceClass;
                }
            }
        }

        // Try to detect from backtrace
        $serviceClass = $this->detectFromBacktrace();
        if ($serviceClass) {
            return $serviceClass;
        }

        // Try to detect from host if base_uri is provided
        if (isset($options['base_uri'])) {
            $host = parse_url($options['base_uri'], PHP_URL_HOST);
            if ($host) {
                return $this->detectFromHost($host);
            }
        }

        return null;
    }

    /**
     * Detect service from PHP backtrace.
     */
    private function detectFromBacktrace(): ?string
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($backtrace as $frame) {
            if (! isset($frame['class'])) {
                continue;
            }

            $class = $frame['class'];

            // Skip framework and vendor classes
            if ($this->isFrameworkClass($class)) {
                continue;
            }

            // Check if this class is registered as a service
            if (ServiceRegistry::isRegistered($class)) {
                return $class;
            }

            // Check if this might be a service class based on naming
            if ($this->looksLikeServiceClass($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Detect service from host.
     */
    private function detectFromHost(string $host): ?string
    {
        return ServiceRegistry::findServiceByHost($host);
    }

    /**
     * Check if a class is a framework or vendor class.
     */
    private function isFrameworkClass(string $class): bool
    {
        $ignoredNamespaces = [
            'Illuminate\\',
            'Laravel\\',
            'Symfony\\',
            'GuzzleHttp\\',
            'Psr\\',
            'Ameax\\ApiLogger\\',
            'Ameax\\ApiLogger\\',
        ];

        foreach ($ignoredNamespaces as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a class name looks like a service class.
     */
    private function looksLikeServiceClass(string $class): bool
    {
        $servicePatterns = [
            'Service',
            'Client',
            'Api',
            'Repository',
            'Gateway',
            'Connector',
            'Integration',
        ];

        foreach ($servicePatterns as $pattern) {
            if (str_contains($class, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract service metadata from the context.
     */
    public function extractMetadata(array $options = []): array
    {
        $metadata = [];

        // Extract from options
        if (isset($options['service_metadata'])) {
            $metadata = array_merge($metadata, $options['service_metadata']);
        }

        // Add service version if available
        if (isset($options['service_version'])) {
            $metadata['service_version'] = $options['service_version'];
        }

        // Add environment context
        $metadata['environment'] = app()->environment();

        // Add timestamp
        $metadata['detected_at'] = now()->toIso8601String();

        return $metadata;
    }
}
