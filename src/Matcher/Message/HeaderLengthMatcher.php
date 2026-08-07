<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

/**
 * Matches headers whose length (in bytes) is equal or greater than a given value.
 *
 * @todo besides GE matching, allow LE matching (using 'match_all' when header name is a wildcard)
 */
class HeaderLengthMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    protected string $headerName;
    protected bool $headerNameIsRegex = false;
    protected int $length;
    protected bool $matchGreaterOrEqualThan;

    /**
     * NB: when passed a header name regex, depending on $matchGreaterOrEqualThan, returns true if
     * - at _least one_ header is long $length or more, or
     * - _all_ headers are long $length or less
     * NB: for headers present multiple times, the length is calculated concatenating those with ', '
     * @param int $length in bytes
     * @param bool $matchGreaterOrEqualThan when false, matchLessOrEqual will be applied
     * @throws \Exception
     */
    public function __construct(string $headerName, int $length, bool $matchGreaterOrEqualThan = true, bool $expandWildcardsInName = false)
    {
        $this->length = $length;
        $this->matchGreaterOrEqualThan = $matchGreaterOrEqualThan;
        $this->headerNameIsRegex = $expandWildcardsInName;

        if ($expandWildcardsInName) {
            $this->headerName = $this->regexpDelimiter . $this->wildcardStringToRegexp($headerName, true) . $this->regexpDelimiter . 'i';
        } else {
            $this->headerName = strtolower($headerName);
        }
    }

    public function matchesMessage(RequestInterface|ResponseInterface $message): bool
    {
        if ($this->matchGreaterOrEqualThan) {
            if ($this->headerNameIsRegex) {
                // Returns true when at least one header satisfies the GE condition
                foreach ($message->getHeaders() as $headerName => $headerValues) {
                    if (preg_match($this->headerName, $headerName)) {
                        if (strlen(implode(', ', $headerValues)) >= $this->length) {
                            return true;
                        }
                    }
                }
                return false;
            } else {
                if (!$message->hasHeader($this->headerName)) {
                    return false;
                }
                $headerValues = $message->getHeader($this->headerName);
                return strlen(implode(', ', $headerValues)) >= $this->length;
            }
        } else {
            if ($this->headerNameIsRegex) {
                $ok = true;
                // Returns true when all matching headers satisfy the LE condition
                foreach ($message->getHeaders() as $headerName => $headerValues) {
                    if (preg_match($this->headerName, $headerName)) {
                        if (strlen(implode(', ', $headerValues)) > $this->length) {
                            $ok = false;
                            break;
                        }
                    }
                }
                return $ok;
            } else {
                if (!$message->hasHeader($this->headerName)) {
                    return true;
                }
                $headerValues = $message->getHeader($this->headerName);
                return strlen(implode(', ', $headerValues)) <= $this->length;
            }
        }
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
