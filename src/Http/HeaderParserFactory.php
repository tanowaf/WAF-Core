<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\ConfigurationError;

class HeaderParserFactory
{
    use LoggerAwareTrait;

    public function __construct(LoggerInterface|null $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @throws ConfigurationError
     */
    public function fromConfiguration(array $configuration): HeaderParser
    {
/// @todo... allow adding custom headers spec via configuration
        if ($configuration) {
            throw new ConfigurationError("Configuration for custom headers is not yet supported");
        }
        $customHeadersSpec = [];

        return new HeaderParser($customHeadersSpec, $this->logger);
    }
}
