<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ServerFilterChain implements ServerBidirectionalFilterInterface
{
    /** @var ClientBidirectionalFilterInterface[] */
    protected array $filters = [];
    /** @var ServerRequestInterface[] */
    protected array $requestChain = [];

    public function __construct(array $filters)
    {
        foreach ($filters as $filter) {
            $this->addFilter($filter);
        }
    }

    public function addFilter(ClientBidirectionalFilterInterface $filter)
    {
        $this->filters[] = $filter;
    }

    public function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
    {
        $this->requestChain = [];
        foreach ($this->filters as $filter) {
            $this->requestChain[] = $request;
            $request = $filter->filterServerRequest($request);
            if ($request instanceof ResponseInterface) {
                $this->requestChain = [];
                return $request;
            }
        }
        return $request;
    }

    public function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        for ($i = count($this->filters) - 1; $i >= 0; $i--) {
            $response = $this->filters[$i]->filterResponse($response, $this->requestChain[$i]);
        }
        $this->requestChain = [];
        return $response;
    }
}
