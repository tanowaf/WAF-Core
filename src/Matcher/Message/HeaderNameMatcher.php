<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

/**
 * Matches either
 * - the presence of a header based on its name (this is faster than doing the same by checking its value's length or value match with '*'), or
 * - the fact that all headers are part of a known list
 */
class HeaderNameMatcher extends BaseMatcher
{
    /// @todo... this matcher does not need the full RegExpListMatcherTrait, just $this->regexpDelimiter and $this->wildcardStringToRegexp
    ///          Otoh there is some constructor arg validation logic to share between HeaderNameMatcher, HeaderLengthMatcher and HeaderRFCComplianceMatcher
    use RegExpListMatcherTrait;

    /** @var string[] */
    protected array $headerNames = [];
    protected bool $headerNameIsRegex = false;
    protected bool $matchAllHeaders = false;

    /**
     * Using wildcards does not make a lot of sense for positive matching, but it does when fe. prepending this with a negation matcher...
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $headerNames, bool $expandWildcardsInName = true, bool $matchAllHeaders = false)
    {
        $this->matchAllHeaders = $matchAllHeaders;
        $this->headerNameIsRegex = $expandWildcardsInName;

        if (is_string($headerNames)) {
            $headerNames = [$headerNames];
        }
        foreach($headerNames as $headerName) {
            if ($expandWildcardsInName) {
                $this->headerNames[] = $this->regexpDelimiter . $this->wildcardStringToRegexp($headerName, true) . $this->regexpDelimiter . 'i';
            } else {
                $this->headerNames[] = strtolower($headerName);
            }
        }
    }

    public function matchesMessage(RequestInterface|ResponseInterface $message): bool
    {
        if ($this->matchAllHeaders) {
            if ($this->headerNameIsRegex) {
                foreach ($message->getHeaders() as $headerName => $headerValues) {
                    $found = false;
                    foreach ($this->headerNames as $headerNameRegex) {
                        if (preg_match($headerNameRegex, $headerName)) {
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
                foreach ($message->getHeaders() as $headerName => $headerValues) {
                    if (!in_array(strtolower($headerName), $this->headerNames)) {
                        return false;
                    }
                }
                return true;
            }
        } else {
            if ($this->headerNameIsRegex) {
                foreach ($this->headerNames as $headerNameRegex) {
                    $found = false;
                    foreach ($message->getHeaders() as $headerName => $headerValues) {
                        if (preg_match($headerNameRegex, $headerName)) {
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
                foreach ($this->headerNames as $headerName) {
                    if (!$message->hasHeader($headerName)) {
                        return false;
                    };
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
