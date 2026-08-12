<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Http\HeaderParsingCapableInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

/// @todo... also validate if header is in correct msg type (req/resp)
class HeaderRFCComplianceMatcher extends BaseMatcher
{
    /// @todo... this matcher does not need the full RegExpListMatcherTrait, just $this->regexpDelimiter and $this->wildcardStringToRegexp
    ///          Otoh there is some constructor arg validation logic to share between HeaderNameMatcher, HeaderLengthMatcher and HeaderRFCComplianceMatcher
    use RegExpListMatcherTrait;

    /** @var string[] */
    protected array $headerNames = [];
    protected bool $headerNameIsRegex = false;

    /**
     * @param string|string[] $headerNames
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $headerNames, bool $expandWildcardsInName = false)
    {
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
        if (!$message instanceof HeaderParsingCapableInterface) {
            throw new \Exception("HeaderValueMatcher needs a headerParsingCapableInterface message to operate on, gotten a " . get_class($message));
        }

        if ($this->headerNameIsRegex) {
            foreach ($message->getHeaders() as $headerName => $headerValues) {
                foreach ($this->headerNames as $headerNameRegex) {
                    if (preg_match($headerNameRegex, $headerName)) {
                        if (!$message->validateHeaderValue($headerName)) {
                            return false;
                        }
                    }
                }
            }
            return true;
        } else {
            foreach($this->headerNames as $headerName) {
                if (!$message->hasHeader($headerName)) {
                    continue;
                }
                if (!$message->validateHeaderValue($headerName)) {
                    return false;
                }
            }
            return true;
        }
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
