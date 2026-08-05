<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;

abstract class BaseMatcher implements RequestMatcherInterface
{
    /**
     * @param ...$items
     * @return bool
     * @throws \Exception
     */
    public function matches(...$items): bool
    {
        if (count($items) !== 1 || ! $items[0] instanceof ServerRequestInterface) {
            throw new \InvalidArgumentException('Request Matcher expected a ServerRequestInterface but got instead a ' . gettype($items[0]));
        }

        return $this->matchesRequest($items[0]);
    }

    abstract function matchesRequest(ServerRequestInterface $request): bool;
}
