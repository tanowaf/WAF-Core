<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

class LoggerChain extends AbstractLogger
{
    /** @var LoggerInterface[] */
    protected array $loggers = [];

    /**
     * @param LoggerInterface[] $loggers
     */
    public function __construct(array $loggers)
    {
        foreach ($loggers as $logger) {
            $this->addLogger($logger);
        }
    }

    public function addLogger(LoggerInterface $logger): void
    {
        $this->loggers[] = $logger;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            $logger->log($level, $message, $context);
        }
    }
}
