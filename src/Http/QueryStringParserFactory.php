<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

use TanoWAF\WAFCore\Exception\ConfigurationError;

class QueryStringParserFactory
{
    /**
     * @todo... allow config to specify the style of QS parsing to use
     * @throws ConfigurationError
     */
    public function fromConfiguration(array $config): QueryStringParser
    {
        if ($config) {
            throw new ConfigurationError('No configuration supported yet for the QueryStringParser');
        }

        return new QueryStringParser();
    }
}
