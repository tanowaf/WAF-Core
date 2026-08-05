<?php

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ResponseHeaderRemover extends MiddlewareFilter implements ClientBidirectionalFilterInterface
{
    protected array $overrideHeaders = [];
    protected array $overriddenHeaders = [];

    public function __construct(array $headers)
    {
        $this->overrideHeaders = $headers;
    }

    public function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request;
    }

    public function filterClientRequest(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    public function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $this->overriddenHeaders = [];
        foreach ($this->overrideHeaders as $name) {
            if ($response->hasHeader($name)) {
                $this->overriddenHeaders[$name] = $response->getHeader($name);
            }
            $response = $response->withoutHeader($name);
        }

        return $response;
    }
}
