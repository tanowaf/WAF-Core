<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

/**
 * Matches headers based on name. Useful to check for presence of a header (it is faster than checking length or value).
 */
class HeaderNameMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    protected string $headerName;
    protected bool $headerNameIsRegex = false;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $headerName, bool $expandWildcardsInName = false)
    {
        $this->headerNameIsRegex = $expandWildcardsInName;

        if ($expandWildcardsInName) {
            $this->headerName = $this->regexpDelimiter . $this->wildcardStringToRegexp($headerName, true) . $this->regexpDelimiter . 'i';
        } else {
            $this->headerName = strtolower($headerName);
        }
    }

    public function matchesMessage(RequestInterface|ResponseInterface $message): bool
    {
        if ($this->headerNameIsRegex) {
            foreach ($message->getHeaders() as $headerName => $headerValues) {
                if (preg_match($this->headerName, $headerName)) {
                    return true;
                }
            }
            return false;
        } else {
            return $message->hasHeader($this->headerName);
        }
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
