<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Logger;

use Psr\Log\LogLevel;

trait ConditionalLoggerTrait
{
    protected const RFC_5424_LEVELS = [
        LogLevel::DEBUG => 7,
        LogLevel::INFO => 6,
        LogLevel::NOTICE => 5,
        LogLevel::WARNING => 4,
        LogLevel::ERROR => 3,
        LogLevel::CRITICAL => 2,
        LogLevel::ALERT => 1,
        LogLevel::EMERGENCY => 0,
    ];

    protected int $level;

    protected function isHandling(string|int $level): bool
    {
        return $this->toNumericLevel($level) <= $this->level;
    }

    /**
     * Sets minimum logging level at which this handler will be triggered.
     *
     * @param int|string $level Level or level name
     * @return $this
     */
    public function setLevel(int|string $level): self
    {
        $this->level = $this->toNumericLevel($level);

        return $this;
    }

    /**
     * Gets minimum logging level at which this handler will be triggered.
     */
    public function getLevel(): int
    {
        return $this->level;
    }

    protected function toNumericLevel(int|string $level): int
    {
        if (is_string($level)) {
            if (ctype_digit($level)) {
                return (int)$level;
            } else {
/// @todo emit a warning if receiving an unknown log level - using trigger_error ?
                return self::RFC_5424_LEVELS[$level] ?? 0;
            }
        }
        return $level;
    }
}
