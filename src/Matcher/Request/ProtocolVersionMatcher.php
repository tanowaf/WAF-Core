<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class ProtocolVersionMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    /**
     * @param string|string[] $filter
     * @throws \Exception
     */
    public function __construct(string|array $filter)
    {
        $this->caseInsensitive = true;
/// @todo... give a warning (or even fail?) if passed in '*'
        $this->setMatchingValues($filter);
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
        return $this->matchesRegexp($request->getProtocolVersion());
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
