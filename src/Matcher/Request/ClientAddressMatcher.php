<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;
use TanoWAF\WAFCore\ServerRequest\Psr7\Attributes;

class ClientAddressMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    /**
     * @param string|string[] $filter
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $filter, bool $expandWildcards = true)
    {
        $this->expandWildcards = $expandWildcards;
/// @todo... validate that the passed in value(s) is an ipv4, ipv6 or has *
/// @todo... give a warning (or even fail?) if passed in '*' and expandWildcards is true
        $this->setMatchingValues($filter);
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
        /// @todo... log a warning if we are not passed the attributes bag or this specific attribute
        $clientAddress = $request->getAttribute(Attributes::class)?->get(Attributes::REMOTE_ADDR) ?? '';
        return $this->matchesRegexp($clientAddress);
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
