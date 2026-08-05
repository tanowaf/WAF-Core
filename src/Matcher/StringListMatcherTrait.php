<?php

namespace TanoWAF\WAFCore\Matcher;

/**
 * Allows matching a string that must fall within an allowed list of values
 */
trait StringListMatcherTrait
{
    /** @var string[] $matchingStrings */
    protected array $matchingStrings;

    /**
     * @param string|string[] $values
     * @throws \Exception
     */
    protected function setMatchingStrings(string|array $values): void
    {
        if (is_array($values)) {
            if (!$values) {
                throw new \Exception('At least one string is required as argument to the matcher');
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new \Exception('Only arrays of strings are allowed as argument to the matcher');
                }
                $this->matchingStrings[$this->normalizeMatchingString($value)] = true;
            }
        } else {
            $this->matchingStrings = [$this->normalizeMatchingString($values) => true];
        }
    }

    /**
     * To be reimplemented in subclasses
     * @param string $value
     * @return string
     */
    protected function normalizeMatchingString(string $value): string
    {
        return $value;
    }

    protected function matchesString(string $value): bool
    {
        return array_key_exists($value, $this->matchingStrings);
    }
}
