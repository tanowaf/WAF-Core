<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\MatcherInterface;

/// @todo check: can we avoid making this extend MatcherInterface?
interface RequestMatcherInterface extends MatcherInterface
{
    function matchesRequest(ServerRequestInterface $request): bool;
}
