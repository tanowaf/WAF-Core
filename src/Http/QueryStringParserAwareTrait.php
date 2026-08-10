<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

trait QueryStringParserAwareTrait
{
    protected QueryStringParserInterface $queryStringParser;

    public function setQueryStringParser(QueryStringParserInterface $queryStringParser): void
    {
        $this->queryStringParser = $queryStringParser;
    }
}
