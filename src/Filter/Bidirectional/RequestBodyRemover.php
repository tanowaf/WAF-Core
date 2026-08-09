<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RequestBodyRemover extends MiddlewareFilter implements ClientBidirectionalFilterInterface
{
/// @todo... review the list of headers
    protected array $overrideHeaders = ['Content-Digest', 'Content-Disposition', 'Content-Language', 'Content-Length', 'Content-Range', 'Content-Type',];
    //protected array $overriddenHeaders = [];

    public function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        /** @var ServerRequestInterface $request */
        $request = $this->filterClientRequest($request);
        return $request;
    }

    public function filterClientRequest(RequestInterface $request): RequestInterface
    {
        //$this->overriddenHeaders = [];
        foreach ($this->overrideHeaders as $name) {
            if ($request->hasHeader($name)) {
                //$this->overriddenHeaders[$name] = $request->getHeader($name);
                $request = $request->withoutHeader($name);
            }
        }
        /// @todo... is there a better way than this to remove the body?
        ///          Should we clone the request - but not its body?
        ///          Should we use a bespoke null-stream implementation?
        return $request->withBody(Stream::create());
    }

    public function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        return $response;
    }
}
