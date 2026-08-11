<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

trait HeaderParserAwareTrait
{
    protected HeaderParserInterface|null $headerParser = null;

    public function setHeaderParser(HeaderParserInterface $headerParser): static
    {
        $this->headerParser = $headerParser;
        return $this;
    }
}
