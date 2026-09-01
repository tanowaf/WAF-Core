<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\RequestInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

/**
 * Matches either
 * - the presence of a query string param based on its name (this is faster than doing the same by checking its value's length or value match with '*'), or
 * - the fact that all query string params are part of a known list
 */
class QueryStringParamNameMatcher extends BaseMatcher
{
    /// @todo... this matcher does not need the full RegExpListMatcherTrait, just $this->regexpDelimiter and $this->wildcardStringToRegexp
    ///          Otoh there is some constructor arg validation logic to share between HeaderNameMatcher, HeaderLengthMatcher and HeaderRFCComplianceMatcher
    use RegExpListMatcherTrait;

    /** @var string[] */
    protected array $parameterNames = [];
    protected bool $parameterNameIsRegex = false;
    protected bool $matchAllQSParams = false;

    /**
     * Using wildcards does not make a lot of sense for positive matching, but it does when fe. prepending this with a negation matcher...
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $parameterNames, bool $expandWildcardsInName = true, bool $matchAllQSParams = false)
    {
        $this->matchAllQSParams = $matchAllQSParams;
        $this->parameterNameIsRegex = $expandWildcardsInName;

        if (is_string($parameterNames)) {
            $parameterNames = [$parameterNames];
        }
        foreach($parameterNames as $parameterName) {
            if ($expandWildcardsInName) {
                $this->parameterNames[] = $this->regexpDelimiter . $this->wildcardStringToRegexp($parameterName, true) . $this->regexpDelimiter . 'i';
            } else {
                $this->parameterNames[] = $parameterName;
            }
        }
    }

    public function matchesRequest(RequestInterface $request): bool
    {
        /// @todo... note that atm we are kind of abusing the ServerRequestInterface method `getQueryParams`:
        ///          no other class but our own ServerRequest will use the queryStringParser to build the values returned
        ///          (take this into account as well when developing a cookie matcher/filter and its interfaces)
        $queryParams = $request->getQueryParams();

        /// @todo optimize: would it be faster to do `$queryParams = array_keys($queryParams)` then use php array functions?

        if ($this->matchAllQSParams) {
            if ($this->parameterNameIsRegex) {
                foreach ($queryParams as $parameterName => $value) {
                    $found = false;
                    foreach ($this->parameterNames as $parameterNameRegex) {
                        /// @todo the casting should happen when creating $request
                        if (preg_match($parameterNameRegex, (string)$parameterName)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        return false;
                    }
                }
                return true;
            } else {
                /// @todo would it be faster to use array_diff_key?
                foreach ($queryParams as $parameterName => $value) {
                    if (!in_array($parameterName, $this->parameterNames)) {
                        return false;
                    }
                }
                return true;
            }
        } else {
            if ($this->parameterNameIsRegex) {
                foreach ($this->parameterNames as $parameterNameRegex) {
                    $found = false;
                    foreach ($queryParams as $parameterName => $value) {
                        /// @todo the casting should happen when creating $message
                        if (preg_match($parameterNameRegex, (string)$parameterName)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        return false;
                    }
                }
                return true;
            } else {
                /// @todo optimize - use array functions?
                foreach ($this->parameterNames as $parameterName) {
                    //$found = false;
                    //foreach ($queryParams as $qsParameterName => $value) {
                    //    if (in_array($parameterName, $this->parameterNames)) {
                    //        $found = true;
                    //        break;
                    //    }
                    //}
                    if (! array_key_exists($parameterName, $queryParams)) {
                        return false;
                    }
                }
                return true;
            }
        }
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
