<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

/**
 * @todo... allow config to specify the style/algorithm of QS parsing to use
 */
class QueryStringParser implements QueryStringParserInterface
{
    /**
     * @return string[]
     */
    public function parseQueryString(string $qs): array
    {
        \parse_str($qs, $qp);
        return $qp;
    }
}
