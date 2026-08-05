<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Sends log messages to a file
 */
class FileLogger extends AbstractLogger
{
    //protected $format;
    protected string $fileName;
    // Timestamp format used is the same as used by Nginx by default, eg `[03/Jun/2025:14:28:21 +0000]`. PHP-FPM by default goes for `[19-Feb-2026 18:09:50]`
    protected string $timestampFormat = 'd/M/Y:H:i:s O';

    use ConditionalLoggerTrait;

    /// @todo (feature creep) allow log file creation+truncation on logger creation
    public function __construct(string $fileName, string $level = LogLevel::WARNING /*, string $format=''*/)
    {
        $this->fileName = $fileName;
        $this->setLevel($level);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->isHandling($level)) {
            # @todo log a message using error_log of this fails?
            file_put_contents($this->fileName, $this->formatMessage($level, $message, $context) . "\n", FILE_APPEND);
        }
    }

    protected function formatMessage($level, string|\Stringable $message, array $context = []): string
    {
/// @todo... add context data
        return '[' . (new \DateTime())->format($this->timestampFormat) . '] ' . ucfirst($level) . ": $message";
    }
}
