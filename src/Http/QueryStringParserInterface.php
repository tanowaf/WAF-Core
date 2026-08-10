<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

interface QueryStringParserInterface
{
    /**
     * @return string[]
     */
    public function parseQueryString(string $qs): array;
}
