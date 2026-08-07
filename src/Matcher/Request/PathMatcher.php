<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class PathMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    protected string $prefixRegexp;

    /**
     * @param string|string[] $filter
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $filter, string $prefixRegexp = '', bool $caseInsensitive = false, bool $expandWildcards = true)
    {
        $this->expandWildcards = $expandWildcards;
        $this->prefixRegexp = $prefixRegexp;
        $this->setMatchingValues($filter, $caseInsensitive);
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
        return $this->matchesRegexp($request->getUri()->getPath());
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        //return $this->wildcardStringToRegexp($this->prefix . $value);
        return '^' . $this->prefixRegexp . substr($this->wildcardStringToRegexp($value), 1);
    }
}
