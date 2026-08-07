<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher;

interface MatcherFactoryInterface
{
    public function supports(string $type): bool;

    /**
     * @param string $type
     * @param mixed $values
     * @return MatcherInterface
     * @throws \Exception
     * @todo reduce the scope of possible exceptions thrown
     */
    public function fromConfiguration(string $type, mixed $values): MatcherInterface;
}
