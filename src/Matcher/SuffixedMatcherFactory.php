<?php

namespace TanoWAF\WAFCore\Matcher;

/**
 * Common functionality for most matcher factories: supports a known list of matcher types, identified by a string
 * and optionally with a suffix such as `:1`, `:99999`.
 * The suffix is useful when many matchers of the same type have to be used in an array with the type as key
 */
abstract class SuffixedMatcherFactory
{
    /** @var string[] */
    protected array $supportedMatcherTypes;
    protected string $matcherTypeSuffixRegexp = '/:[0-9]+$/';

    public function supports(string $type): bool
    {
        return in_array($this->getMatcherType($type), $this->supportedMatcherTypes);
    }

    protected function getMatcherType(string $type): string
    {
        /// @todo should we leave in the strtolower and trim, or require config to be always perfectly correct?
        return strtolower(preg_replace($this->matcherTypeSuffixRegexp, '', trim($type)));
    }
}
