<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

interface QueryStringParserInterface
{
    /**
     * @return string[]
     */
    public function parseQueryString(string $queryString, array|null &$errorsFound = []): array;
}
