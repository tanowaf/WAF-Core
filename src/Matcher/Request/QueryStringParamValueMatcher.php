<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class QueryStringParamValueMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    protected string $parameterName;
    protected bool $parameterNameIsRegex = false;

    /**
     * @param string|string[] $filter
     * @throws \InvalidArgumentException
     */
    public function __construct(string $parameterName, string|array $filter, bool $caseInsensitive = false, bool $expandWildcards = true,
        bool $expandWildcardsInName = false)
    {
        $this->expandWildcards = $expandWildcards;
        $this->parameterNameIsRegex = $expandWildcardsInName;
        if ($this->parameterNameIsRegex) {
            $this->parameterName = $this->regexpDelimiter . $this->wildcardStringToRegexp($parameterName, true) . $this->regexpDelimiter;
        } else {
            $this->parameterName = $parameterName;
        }

        $this->setMatchingValues($filter, $caseInsensitive);
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
/// @todo... start from getQuery, and tokenize the QS into pieces via a QueryStringParser which can be tweaked
///          to work according to PHP rules or different rules ()
        $pieces = $request->getQueryParams();
        //$qs = $request->getUri()->getQuery();
        //parse_str($qs, $pieces);
        if ($this->parameterNameIsRegex) {
            foreach ($pieces as $name => $value) {
                if (preg_match($this->parameterName, (string)$name)) {
                    if (is_array($value)) {
                        foreach ($value as $val) {
                            if ($this->matchesRegexp($val)) {
                                return true;
                            }
                        }
                        return false;
                    } else {
                        return $this->matchesRegexp($value);
                    }
                }
            }
            return false;
        } else {
            if (!array_key_exists($this->parameterName, $pieces)) {
                return false;
            }
            $value = $pieces[$this->parameterName];
            if (is_array($value)) {
                foreach ($value as $val) {
                    if ($this->matchesRegexp($val)) {
                        return true;
                    }
                }
                return false;
            } else {
                return $this->matchesRegexp($value);
            }
        }
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
