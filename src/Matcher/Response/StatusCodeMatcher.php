<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Response;

use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class StatusCodeMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    /**
     * @see https://www.rfc-editor.org/info/rfc9110/#name-status-codes
     * @param int|string|string[]|int[] $filter
     * @throws \Exception
     */
    public function __construct(int|string|array $filter, bool $expandWildcards = true)
    {
        $this->expandWildcards = $expandWildcards;
        if (is_int($filter)) {
            $filter = (string)$filter;
        } else {
/// @todo... check that the passed in values match either a int string between 100 and 599, or a wildcard pattern
        }
/// @todo... cast ints to strings when an array is passed in
        $this->setMatchingValues($filter);
    }

    public function matchesResponse(ResponseInterface $response): bool
    {
        return $this->matchesRegexp((string)$response->getStatusCode());
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
