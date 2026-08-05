<?php

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Tracer\RequestTracerTrait;
use TanoWAF\WAFCore\Tracer\ResponseTracerTrait;

class Tracer extends MiddlewareFilter implements ClientBidirectionalFilterInterface
{
    use RequestTracerTrait;
    use ResponseTracerTrait;

    protected string $fileName;
    protected string $requestPrefix;
    protected string $responsePrefix;

    public function __construct(string $fileName, string $requestPrefix = '> ', string $responsePrefix = '< ')
    {
        $this->fileName = $fileName;
        $this->requestPrefix = $requestPrefix;
        $this->responsePrefix = $responsePrefix;
    }

    public function filterClientRequest(RequestInterface $request): RequestInterface
    {
        file_put_contents($this->fileName, $this->serializeRequest($request), FILE_APPEND);
        return $request;
    }

    public function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        file_put_contents($this->fileName, $this->serializeRequest($request), FILE_APPEND);
        return $request;
    }

    public function filterResponse(ResponseInterface $response, RequestInterface $request): ResponseInterface
    {
        file_put_contents($this->fileName, $this->serializeResponse($response), FILE_APPEND);
        return $response;
    }
}
