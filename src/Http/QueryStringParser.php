<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

/**
 * @todo... allow config to specify the style/algorithm of QS parsing to use
 */
class QueryStringParser implements QueryStringParserInterface
{
    /**
     * Parses the value of the Query String, turning it in a key => value list.
     * NB: there is no RFC describing the process to use for this. Different programming languages / sdks exhibit
     * surprisingly different behaviour, esp. regarding arrays and duplicates.
     *
     * @return string[]
     */
    public function parseQueryString(string $queryString, array|null &$errorsFound = []): array
    {
        $errorsFound = [];
        \parse_str($queryString, $qp);
        return $qp;
    }
}
