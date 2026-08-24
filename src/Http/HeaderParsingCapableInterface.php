<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

interface HeaderParsingCapableInterface
{
    public function validateHeaderValue(string $headerName, array|null &$errorsFound = []): bool;

    /**
     * @return string[]
     */
    public function normalizedHeaderValue(string $headerName, array|null &$errorsFound = []): array;
}
