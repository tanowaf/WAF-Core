<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

interface HeaderParsingCapableInterface
{
    public function validateHeaderValue(string $headerName): bool;

    /**
     * @return string[]
     */
    public function normalizedHeaderValue(string $headerName, array|null &$errorsFound = []): array;
}
