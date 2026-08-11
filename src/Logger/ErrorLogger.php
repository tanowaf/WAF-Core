<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Logger;

use Psr\Log\LogLevel;

/**
 * Sends log messages to the php error log; swallows log messages of severity lesser than WARNING
 * @todo implement ConditionalLoggerTrait
 */
class ErrorLogger
{
    static array $map = [
        Loglevel::EMERGENCY => true,
        Loglevel::ALERT     => true,
        Loglevel::CRITICAL  => true,
        Loglevel::ERROR     => true,
        Loglevel::WARNING   => true,
        Loglevel::NOTICE    => false,
        Loglevel::INFO      => false,
        Loglevel::DEBUG     => false,
    ];

    protected string $logLinePrefix = 'WAFCore ';

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (@static::$map[$level]) {
/// @todo... add context data
            $message = $this->logLinePrefix . ucfirst($level) . ': ' . $message;
            error_log($message);
        }
    }
}
