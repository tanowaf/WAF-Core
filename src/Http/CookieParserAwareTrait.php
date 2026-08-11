<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

trait CookieParserAwareTrait
{
    protected CookieParserInterface|null $cookieParser = null;

    public function setCookieParser(CookieParserInterface $cookieParser): static
    {
        $this->cookieParser = $cookieParser;
        return $this;
    }
}
