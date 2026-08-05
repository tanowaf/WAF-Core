<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Message;

class ContentTypeMatcher extends HeaderValueMatcher
{
    /**
     * @param string|string[] $filter
     * @throws \Exception
     * @todo... different parts of the Content-Type header might need to be matched differently on case: mimetype is case-insensitive, but boundary is not
     */
    public function __construct(string|array $filter, bool $expandWildcards = true)
    {
        parent::__construct('content-type', $filter, true, $expandWildcards);
    }
}
