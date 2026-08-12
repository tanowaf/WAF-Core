<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Http\HeaderParsingCapableInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class HeaderValueMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    protected string $headerName;
    protected bool $headerNameIsRegex = false;

    /**
     * NB: when passed a header name regex, returns true if at _least one_ header value matches
     * @param string|string[] $filter
     * @throws \InvalidArgumentException
     */
    public function __construct(string $headerName, string|array $filter, bool $caseInsensitive = false, bool $expandWildcards = true,
        bool $expandWildcardsInName = false/*, $matchInvalidHeaderValues = false*/)
    {
        $this->expandWildcards = $expandWildcards;
        $this->headerNameIsRegex = $expandWildcardsInName;

        if ($expandWildcardsInName) {
            $this->headerName = $this->regexpDelimiter . $this->wildcardStringToRegexp($headerName, true) . $this->regexpDelimiter . 'i';
        } else {
            $this->headerName = strtolower($headerName);
        }

        $this->setMatchingValues($filter, $caseInsensitive);
    }

    public function matchesMessage(RequestInterface|ResponseInterface $message): bool
    {
        if (!$message instanceof HeaderParsingCapableInterface) {
            throw new \Exception("HeaderValueMatcher needs a headerParsingCapableInterface message to operate on, gotten a " . get_class($message));
        }

        if ($this->headerNameIsRegex) {
            foreach ($message->getHeaders() as $headerName => $headerValues) {
                if (preg_match($this->headerName, $headerName)) {
/// @todo... log a debug message if parsing finds errors (here or...?)
                    $parsedValues = $message->normalizedHeaderValue(strtolower($headerName), $errors);
                    foreach ($parsedValues as $headerValue) {
                        if ($this->matchesRegexp($headerValue)) {
                            return true;
                        }
                    }
                }
            }
            return false;
        } else {
            if (!$message->hasHeader($this->headerName)) {
                return false;
            }
            //$headerValues = $message->getHeader($this->headerName);
/// @todo... log a debug message if parsing finds errors (here or...?)
            $parsedValues = $message->normalizedHeaderValue($this->headerName, $errors);
            foreach ($parsedValues as $headerValue) {
                if ($this->matchesRegexp($headerValue)) {
                    return true;
                }
            }
            return false;
        }
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
