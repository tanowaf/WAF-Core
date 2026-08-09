<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

trait HeaderParserAwareTrait
{
    protected HeaderParser $headerParser;

    public function setHeaderParser(HeaderParser $headerParser)
    {
        $this->headerParser = $headerParser;
    }
}
