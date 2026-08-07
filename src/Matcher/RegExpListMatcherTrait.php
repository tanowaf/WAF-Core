<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher;

/**
 * Allows matching a string that must fall within an allowed list of regexes.
 * @todo allow to set (globally?) the regexpDelimiter
 */
trait RegExpListMatcherTrait
{
    protected string $regexpDelimiter = ':';
    protected string $regexp;

    protected bool $expandWildcards = true;
    private bool $matchesAnything = false;

    /**
     * @param string|string[] $values these can be either regexps, glob-expressions or plain strings, depending on the
     *                                conversion done by `normalizeMatchingRegexp`
     * @throws \InvalidArgumentException
     * @todo optimize matching when $expandWildcards is false and $caseInsensitive is false and the value to match is a single string
     */
    protected function setMatchingValues(string|array $values, bool $caseInsensitive = false): void
    {
        if (is_array($values)) {
            if (!$values) {
                throw new \InvalidArgumentException('At least one string is required as argument to the matcher');
            }
            $allowedValues = [];
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Only arrays of strings are allowed as argument to the matcher');
                }
                $regexpPart = $this->normalizeMatchingRegexp($value);
                if ($regexpPart === '.*') {
                    $this->matchesAnything = true;
                }
                $allowedValues[] = $regexpPart;
            }
            $this->regexp = $this->regexpDelimiter . '(' . implode('|', $allowedValues) . ')' . $this->regexpDelimiter;
        } else {
            $regexpPart = $this->normalizeMatchingRegexp($values);
            if ($regexpPart === '.*') {
                $this->matchesAnything = true;
            }
            $this->regexp = $this->regexpDelimiter . $regexpPart . $this->regexpDelimiter;
        }
        if ($caseInsensitive) {
            $this->regexp .= 'i';
        }
    }

    protected function matchesRegexp(string $value): bool
    {
        return $this->matchesAnything === true || (bool)preg_match($this->regexp, $value);
    }

    /**
     * To be reimplemented in subclasses. Transforms the string as set in the settings by the user into the regexp used
     * to match the given values.
     * @param string $value
     * @return string
     */
    protected function normalizeMatchingRegexp(string $value): string
    {
        return preg_quote($value, $this->regexpDelimiter);
    }

    /**
     * A helper method dedicated to transforming a string such as 'hello there' or 'hello *' into a regexp
     */
    protected function wildcardStringToRegexp(string $value, bool $forceWildcardExpansion = false): string
    {
        $regexp = preg_quote($value, $this->regexpDelimiter);
        if ($this->expandWildcards || $forceWildcardExpansion) {
            $regexp = str_replace(['\\*'], ['.*'], $regexp);
        }
        return '^' . $regexp . '$';
    }

    public function getRegexpDelimiter(): string
    {
        return $this->regexpDelimiter;
    }
}
