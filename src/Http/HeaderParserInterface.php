<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

interface HeaderParserInterface
{
    /**
     * @param string[] $values
     * @return string[]
     */
    public function normalizeHeaderValue(string $name, array $values, array|null &$errorsFound = []): array;

    /**
     * @param string[] $values
     */
    public function validateHeaderValue(string $name, array $values, array|null &$errorsFound = []): bool;
}
