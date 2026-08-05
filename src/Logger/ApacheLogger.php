<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Sends log messages to Apache, as notes. Those can be logged by adding `%{YaWAFLogMessage}n` the `LogFormat` directive
 */
class ApacheLogger extends AbstractLogger
{
    const DefaultNoteName = 'YaWAFLogMessage';

    use ConditionalLoggerTrait;

    protected string $noteName;

    public function __construct(string $level = LogLevel::WARNING, string $noteName = self::DefaultNoteName)
    {
        $this->setLevel($level);
        $this->noteName = $noteName;
    }

    /**
     * Logs with an arbitrary level.
     * @param mixed $level
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->isHandling($level)) {
            $value = $this->formatMessage($level, $message, $context);
            apache_note($this->noteName, $value);
        }
    }

    protected function formatMessage($level, string|\Stringable $message, array $context = []): string
    {
/// @todo... add context data
        return ucfirst($level) . ": $message";
    }
}
