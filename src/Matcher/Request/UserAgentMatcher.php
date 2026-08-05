<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use TanoWAF\WAFCore\Matcher\Message\HeaderValueMatcher;

class UserAgentMatcher extends HeaderValueMatcher
{
    /**
     * @param string|string[] $filter
     * @throws \Exception
     */
    public function __construct(string|array $filter, bool $caseInsensitive = false, bool $expandWildcards = true)
    {
        parent::__construct('user-agent', $filter, $caseInsensitive, $expandWildcards);
    }
}
