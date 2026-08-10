<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

trait CookieParserAwareTrait
{
    protected CookieParserInterface $cookieParser;

    public function setCookieParser(CookieParserInterface $cookieParser): void
    {
        $this->cookieParser = $cookieParser;
    }
}
