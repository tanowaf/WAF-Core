<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CallableBasedMiddlewareFilter
{
    use MiddlewareFilterTrait;

    protected $serverRequestCallable;
    protected $responseCallable;
    protected $clientRequestCallable;

    public function __construct(callable $clientRequestCallable, callable $responseCallable, callable|null $serverRequestCallable = null)
    {
        $this->clientRequestCallable = $clientRequestCallable;
        $this->responseCallable = $responseCallable;
        $this->serverRequestCallable = $serverRequestCallable;
    }

    public function filterClientRequest(RequestInterface $request): RequestInterface|ResponseInterface
    {
        return ($this->clientRequestCallable)($request);
    }

    public function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
    {
        if ($this->serverRequestCallable === null) {
            return $this->filterClientRequest($request);
        }
        return ($this->serverRequestCallable)($request);
    }

    public function filterResponse(ResponseInterface $response, RequestInterface $request): ResponseInterface
    {
        return ($this->responseCallable)($response, $request);
    }
}
