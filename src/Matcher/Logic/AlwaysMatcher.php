<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Logic;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\Request\RequestMatcherInterface;
use TanoWAF\WAFCore\Matcher\Response\ResponseMatcherInterface;

class AlwaysMatcher implements RequestMatcherInterface, ResponseMatcherInterface
{
    public function matches(...$items): bool
    {
        /// @todo log a warning if passed in any items
        return true;
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
        return true;
    }

    public function matchesResponse(ResponseInterface $response): bool
    {
        return true;
    }
}
