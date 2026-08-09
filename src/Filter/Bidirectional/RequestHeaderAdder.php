<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/// @todo add an option to merge the existing header value if found
class RequestHeaderAdder extends MiddlewareFilter implements ClientBidirectionalFilterInterface
{
    protected array $overrideHeaders = [];
    protected array $overriddenHeaders = [];

    public function __construct(array $headers)
    {
        $this->overrideHeaders = $headers;
    }

    public function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        /** @var ServerRequestInterface $request */
        $request = $this->filterClientRequest($request);
        return $request;
    }

    public function filterClientRequest(RequestInterface $request): RequestInterface
    {
        $this->overriddenHeaders = [];
        foreach ($this->overrideHeaders as $name => $value) {
            if ($request->hasHeader($name)) {
                $this->overriddenHeaders[$name] = $request->getHeader($name);
            }
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    public function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        return $response;
    }
}
