<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Response;

use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Matcher\MatcherInterface;

/// @todo check: can we avoid making this extend MatcherInterface?
interface ResponseMatcherInterface extends MatcherInterface
{
    function matchesResponse(ResponseInterface $response): bool;
}
