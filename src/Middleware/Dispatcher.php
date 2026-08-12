<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A Middleware "stack"
 */
class Dispatcher implements MiddlewareInterface, RequestHandlerInterface
{
    /** @var MiddlewareInterface[] */
    protected array $middlewares = [];
    protected RequestHandlerInterface $requestHandler;
    protected int $current = 0;

    /**
     * @param MiddlewareInterface[] $middlewares
     */
    public function __construct(array $middlewares)
    {
        foreach ($middlewares as $filter) {
            $this->appendMiddleware($filter);
        }
    }

    public function prependMiddleware(MiddlewareInterface $filter): void
    {
        array_unshift($this->middlewares, $filter);
    }

    public function appendMiddleware(MiddlewareInterface $filter): void
    {
        $this->middlewares[] = $filter;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
/// @todo... should we throw with a nice message if there are 0 middlewares added? It seems more likely to be a config error than a real need
        $this->requestHandler = $handler;
        $this->current = 0;
        return $this->middlewares[$this->current]->process($request, $this);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->current++;
        if ($this->current < count($this->middlewares)) {
            return $this->middlewares[$this->current]->process($request, $this);
        }
        return $this->requestHandler->handle($request);
    }
}
