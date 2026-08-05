<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class PortMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    /**
     * @param string|int|string[]|int[] $filter
     * @throws \Exception
     */
    public function __construct(string|int|array $filter, bool $expandWildcards = true)
    {
        $this->expandWildcards = $expandWildcards;
        if (is_int($filter)) {
            $filter = (string)$filter;
        } else {
/// @todo... validate that the passed in value is only made of 0-9 and *
        }
/// @todo... cast ints to strings when an array is passed in
        $this->setMatchingValues($filter);
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
        // @todo if $request was created by the Psr7\Creator, the port would have been set in `$request->getUri` with
        //       the port from the Host header as preferred choice. For other sources of $request, we can't be 100% sure,
        //       as they might give higher precedence to f.e. $server['SERVER_PORT']. In which case we should replicate
        //       here the logic of checking the 'Host' header as preferred source of truth...
        //$parts = explode(':', $request->getHeaderLine('Host'), 2);
        //if (count($parts) === 2) {
        //    $port = $parts[1];
        //} else {
            $uri = $request->getUri();
            $port = $uri->getPort();
            if ($port === null) {
                switch ($uri->getScheme()) {
                    case 'http':
                        $port = '80';
                        break;
                    case 'https':
                        $port = '443';
                        break;
                    default:
                        $port = '';
                }
            }
        //}

        return $this->matchesRegexp((string)$port);
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
