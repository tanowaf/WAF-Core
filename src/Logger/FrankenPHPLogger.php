<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Sends log messages to the FrankenPHP/Caddy log
 */
class FrankenPHPLogger extends AbstractLogger
{
    static array $map = [
        /** @noinspection PhpUndefinedConstantInspection */
        /** @phpstan-ignore constant.notFound */
        Loglevel::EMERGENCY => FRANKENPHP_LOG_LEVEL_ERROR,
        Loglevel::ALERT     => FRANKENPHP_LOG_LEVEL_ERROR,
        Loglevel::CRITICAL  => FRANKENPHP_LOG_LEVEL_ERROR,
        Loglevel::ERROR     => FRANKENPHP_LOG_LEVEL_ERROR,
        Loglevel::WARNING   => FRANKENPHP_LOG_LEVEL_WARN,
        Loglevel::NOTICE    => FRANKENPHP_LOG_LEVEL_INFO,
        Loglevel::INFO      => FRANKENPHP_LOG_LEVEL_INFO,
        Loglevel::DEBUG     => FRANKENPHP_LOG_LEVEL_DEBUG,
    ];

    /**
     * Logs with an arbitrary level to the Caddy log.
     * @see https://frankenphp.dev/docs/logging/
     * @param mixed $level
     * @throws \Psr\Log\InvalidArgumentException
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        /** @noinspection PhpUndefinedFunctionInspection,PhpUndefinedConstantInspection */
        /** @phpstan-ignore function.notFound,constant.notFound */
        frankenphp_log($message, static::$map[$level] ?? FRANKENPHP_LOG_LEVEL_INFO, $context);
    }
}
