<?php

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Brings the MiddlewareInterface and BidirectionalFilterInterface under one roof
 */
abstract class MiddlewareFilter implements MiddlewareInterface, ServerBidirectionalFilterInterface
{
    use MiddlewareFilterTrait;

    abstract public function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface;

    abstract function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface;
}
