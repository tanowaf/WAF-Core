<?php

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Brings the MiddlewareInterface and ServerBidirectionalFilterInterface under one roof (implements MiddlewareInterface)
 */
trait MiddlewareFilterTrait
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->filterServerRequest($request);
        if ($request instanceof ResponseInterface) {
            return $request;
        }
        $response = $handler->handle($request);
/// @todo should we pass back the original request or the modified one ??? (possibly cloned, have to check immutability...)
        return $this->filterResponse($response, $request);
    }
}
