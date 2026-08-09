<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

class QueryStringParser
{
    /**
     * @return string[]
     */
    public static function parseQueryString(string $qs): array
    {
/// @todo... implement a different algorithm (also, make it switchable and this method non-static?)
        \parse_str($qs, $qp);
        return $qp;
    }
}
