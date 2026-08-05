<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Response;

use Psr\Http\Message\ResponseInterface;

abstract class BaseMatcher implements ResponseMatcherInterface
{
    public function matches(...$items): bool
    {
        if (count($items) !== 1 || ! $items[0] instanceof ResponseInterface) {
            throw new \Exception('Response Matcher expected a ResponseInterface but got instead a ' . gettype($items[0]));
        }

        return $this->matchesResponse($items[0]);
    }

    abstract function matchesResponse(ResponseInterface $response): bool;
}
