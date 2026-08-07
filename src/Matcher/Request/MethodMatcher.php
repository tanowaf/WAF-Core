<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\StringListMatcherTrait;

class MethodMatcher extends BaseMatcher
{
    use StringListMatcherTrait;

    /**
     * NB: the http method is in fact a case-sensitive value.
     * @see https://www.rfc-editor.org/info/rfc9110/#methods
     * @param string|string[] $filter
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $filter)
    {
        if (is_array($filter)) {
            $this->setMatchingStrings(...$filter);
        } else {
            $this->setMatchingStrings($filter);
        }
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
        return $this->matchesString($request->getMethod());
    }

    protected function normalizeMatchingString(string $value): string
    {
        return strtoupper(trim($value));
    }
}
