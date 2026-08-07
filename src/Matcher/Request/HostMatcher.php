<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class HostMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    /**
     * "The scheme and host are case-insensitive and normally provided in lowercase"
     * @see https://www.rfc-editor.org/info/rfc9110/#name-identifiers-in-http
     * @param string|string[] $filter
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $filter, bool $expandWildcards = true)
    {
        $this->expandWildcards = $expandWildcards;
/// @todo... give a warning (or even fail?) if passed in '*' and expandWildcards is true
        $this->setMatchingValues($filter, true);
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
        $host = explode(':', $request->getHeaderLine('Host'), 2)[0];
        return $this->matchesRegexp($host);
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
