<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

interface CookieParserInterface
{
    /**
     * @return string[]
     */
    public function parseCookies(string $cookieString): array;
}
