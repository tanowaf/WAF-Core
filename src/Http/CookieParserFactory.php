<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

use TanoWAF\WAFCore\Exception\ConfigurationError;

class CookieParserFactory
{
    /**
     * @throws ConfigurationError
     */
    public function fromConfiguration(array $config): CookieParser
    {
        if ($config) {
            throw new ConfigurationError('No configuration supported yet for the CookieParser');
        }

        return new CookieParser();
    }
}
