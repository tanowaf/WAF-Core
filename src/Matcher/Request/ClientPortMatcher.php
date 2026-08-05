<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;
use TanoWAF\WAFCore\ServerRequest\Psr7\Attributes;

class ClientPortMatcher extends BaseMatcher
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
        /// @todo... log a warning if we are not passed the attributes bag or this specific attribute
        $clientPort = $request->getAttribute(Attributes::class)?->get(Attributes::REMOTE_PORT) ?? '';

        return $this->matchesRegexp($clientPort);
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
