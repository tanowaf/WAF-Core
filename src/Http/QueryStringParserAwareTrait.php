<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

trait QueryStringParserAwareTrait
{
    protected QueryStringParserInterface|null $queryStringParser = null;

    public function setQueryStringParser(QueryStringParserInterface $queryStringParser): static
    {
        $this->queryStringParser = $queryStringParser;
        return $this;
    }
}
