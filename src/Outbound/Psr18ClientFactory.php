<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Outbound;

use Ameax\ApiLogger\Contracts\OutboundLoggerInterface;
use Ameax\ApiLogger\Support\CorrelationIdManager;
use Psr\Http\Client\ClientInterface;

/**
 * Factory for creating PSR-18 HTTP clients with logging middleware.
 *
 * This factory wraps any PSR-18 compliant HTTP client with the ApiLogger
 * middleware to enable automatic request/response logging.
 */
class Psr18ClientFactory
{
    public function __construct(
        private OutboundLoggerInterface $logger,
        private ?CorrelationIdManager $correlationIdManager = null,
        private ?ServiceDetector $serviceDetector = null
    ) {}

    /**
     * Create a PSR-18 client with logging middleware.
     *
     * @param  ClientInterface  $client  The base PSR-18 client to wrap
     * @param  array  $options  Optional configuration options
     * @return ClientInterface The client wrapped with logging middleware
     */
    public function create(ClientInterface $client, array $options = []): ClientInterface
    {
        return new Psr18LoggerMiddleware(
            $client,
            $this->logger,
            $this->correlationIdManager,
            $this->serviceDetector,
            $options
        );
    }

    /**
     * Static factory method for easier usage.
     */
    public static function make(
        ClientInterface $client,
        OutboundLoggerInterface $logger,
        ?CorrelationIdManager $correlationIdManager = null,
        ?ServiceDetector $serviceDetector = null,
        array $options = []
    ): ClientInterface {
        $factory = new self($logger, $correlationIdManager, $serviceDetector);

        return $factory->create($client, $options);
    }
}
