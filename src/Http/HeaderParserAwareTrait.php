<?php

namespace TanoWAF\WAFCore\Http;

trait HeaderParserAwareTrait
{
    protected HeaderParser $headerParser;

    public function setHeaderParser(HeaderParser $headerParser)
    {
        $this->headerParser = $headerParser;
    }
}
