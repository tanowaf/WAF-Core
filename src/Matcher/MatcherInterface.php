<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher;

interface MatcherInterface
{
    public function matches(...$items): bool;
}
