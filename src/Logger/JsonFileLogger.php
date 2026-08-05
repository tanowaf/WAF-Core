<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Logger;

class JsonFileLogger extends FileLogger
{
    protected function formatMessage($level, string|\Stringable $message, array $context = []): string
    {
        // Q: should we suppress php warnings when failing to encode?
        return json_encode([
            'level' => $level,
            'timestamp' => microtime(true),
            'message' => $message,
            'context' => $context,
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
