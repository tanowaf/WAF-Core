<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

interface HeaderParserInterface
{
    public function normalizeHeaderValue(string $name, array $values, array|null &$errorsFound = []): array;

    public function validateHeaderValue(string $name, array $values, array|null &$errorsFound = []): bool;
}
