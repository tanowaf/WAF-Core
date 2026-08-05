<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class SchemeMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    /**
     * "The scheme and host are case-insensitive and normally provided in lowercase"
     * @see https://www.rfc-editor.org/info/rfc9110/#name-identifiers-in-http
     * @param string|string[] $filter
     * @throws \Exception
     */
    public function __construct(string|array $filter, bool $expandWildcards = true)
    {
        $this->caseInsensitive = true;
        $this->expandWildcards = $expandWildcards;
        $this->setMatchingValues($filter);
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
        return $this->matchesRegexp($request->getUri()->getScheme());
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
