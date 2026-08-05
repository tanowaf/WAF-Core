<?php
declare(strict_types=1);


namespace TanoWAF\WAFCore\Matcher;

trait MatcherFactoryAwareTrait
{
    protected MatcherFactoryInterface $matcherFactory;

    public function setMatcherFactory(MatcherFactoryInterface $matcherFactory): void
    {
        $this->matcherFactory = $matcherFactory;
    }
}
