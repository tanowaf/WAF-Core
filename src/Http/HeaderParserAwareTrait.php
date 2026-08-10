<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

trait HeaderParserAwareTrait
{
    protected HeaderParserInterface $headerParser;

    public function setHeaderParser(HeaderParserInterface $headerParser): void
    {
        $this->headerParser = $headerParser;
    }
}
