<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class HeaderParserFactory
{
    use LoggerAwareTrait;

    protected HeaderParser|null $headerParser = null;
    /** @var HeaderSpec[] */
    protected array $customHeadersSpec;

    public function __construct(array $configuration, LoggerInterface|null $logger = null)
    {
        $this->customHeadersSpec = $configuration;
        $this->logger = $logger;
    }

    /**
     * NB: the returned HeaderParser is a singleton: each HeaderParserFactory instance will only create one
     */
    public function createParser(): HeaderParser
    {
        if ($this->headerParser === null) {
            $this->headerParser = new HeaderParser($this->customHeadersSpec, $this->logger);
        }

        return $this->headerParser;
    }

    /**
     * @return HeaderSpec[]
     */
    protected function fromConfiguration(array $configuration): array
    {
/// @todo... allow adding custom headers spec via a json configuration
        if ($configuration) {
            throw new \Exception("Configuration for custom headers is not yet supported");
        }
        return [];
    }
}
