<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Http\Message\MessageInterface;
use TanoWAF\WAFCore\Http\HeaderParser;
use TanoWAF\WAFCore\Http\HeaderParserAwareTrait;
//use TanoWAF\WAFCore\Http\HeaderParserOnError;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class HeaderValueMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;
    use HeaderParserAwareTrait;

    protected string $headerName;
    protected bool $headerNameIsRegex = false;
    //protected HeaderParserOnError $headerValueParsingMode;

    /**
     * NB: when passed a header name regex, returns true if at _least one_ header value matches
     * @param string|string[] $filter
     * @throws \Exception
     */
    public function __construct(string $headerName, string|array $filter, bool $caseInsensitive = false, bool $expandWildcards = true,
        bool $expandWildcardsInName = false/*, $matchInvalidHeaderValues = false*/)
    {
        $this->caseInsensitive = $caseInsensitive;
        $this->expandWildcards = $expandWildcards;
        $this->headerNameIsRegex = $expandWildcardsInName;
        //$this->headerValueParsingMode = $matchInvalidHeaderValues ? HeaderParserOnError::Ignore : HeaderParserOnError::ReplaceWithSpace;

        if ($expandWildcardsInName) {
            $this->headerName = $this->regexpDelimiter . $this->wildcardStringToRegexp($headerName, true) . $this->regexpDelimiter . 'i';
        } else {
            $this->headerName = strtolower($headerName);
        }

        $this->setMatchingValues($filter);

        $this->headerParser = new HeaderParser();
    }

    public function matchesMessage(MessageInterface $message): bool
    {
        if ($this->headerNameIsRegex) {
            foreach ($message->getHeaders() as $headerName => $headerValues) {
                if (preg_match($this->headerName, $headerName)) {
/// @todo... log a debug message if parsing finds errors and/or allow a 'strict' matching mode
                    $parsedValues = $this->headerParser->normalizeHeaderValue(strtolower($headerName), $headerValues, $errors);
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
            $headerValues = $message->getHeader($this->headerName);
/// @todo... log a debug message if parsing finds errors and/or allow a 'strict' matching mode
            $parsedValues = $this->headerParser->normalizeHeaderValue($this->headerName, $headerValues, $errors);
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
