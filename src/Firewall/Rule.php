<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Firewall;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareTrait;
use TanoWAF\WAFCore\Exception\RequestDenied;
use TanoWAF\WAFCore\Filter\Request\ServerRequestFilterInterface;
use TanoWAF\WAFCore\Filter\Response\ResponseFilterInterface;
use TanoWAF\WAFCore\Logger\PrivateLoggerTrait;
use TanoWAF\WAFCore\Matcher\Logic\AlwaysMatcher;
use TanoWAF\WAFCore\Matcher\Request\RequestMatcherInterface;
use TanoWAF\WAFCore\Matcher\Response\ResponseMatcherInterface;
use TanoWAF\WAFCore\Stdlib;

/// @todo... allow more RuleActions (this might need to move the logic handling those to the firewall...)
class Rule implements RequestMatcherInterface, ServerRequestFilterInterface, ResponseFilterInterface
{
    use LoggerAwareTrait;
    use PrivateLoggerTrait;

    protected RequestMatcherInterface $requestMatcher;
    /** @var ServerRequestFilterInterface[] */
    protected array $requestFilters = [];
    protected RuleAction $requestAction = RuleAction::Allow;
    protected null|ResponseMatcherInterface $responseMatcher;
    /** @var ResponseFilterInterface[] */
    protected array $responseFilters = [];
    protected RuleAction $responseAction = RuleAction::Allow;

    /**
     * @param RequestMatcherInterface $requestMatcher
     * @param ServerRequestFilterInterface[] $requestFilters
     * @param RuleAction $requestAction
     * @param ResponseMatcherInterface|null $responseMatcher
     * @param ResponseFilterInterface[] $responseFilters
     * @param RuleAction $responseAction
     * @throws \Exception
     */
    public function __construct(RequestMatcherInterface $requestMatcher, array $requestFilters = [], RuleAction $requestAction = RuleAction::Allow,
        ResponseMatcherInterface|null $responseMatcher = null, array $responseFilters = [], RuleAction $responseAction = RuleAction::Allow)
    {
        if (! Stdlib::array_of($requestFilters, ServerRequestFilterInterface::class)) {
            throw new \InvalidArgumentException('requestFilters argument to Rule constructor must be an array of RequestFilterInterface');
        }
        if (! Stdlib::array_of($responseFilters, ResponseFilterInterface::class)) {
            throw new \InvalidArgumentException('responseFilters argument to Rule constructor must be an array of ResponseFilterInterface');
        }

/// @todo... review these checks - make sure they are in sync with what is built by the RuleFactory
        if ($requestAction === RuleAction::Deny) {
            if ($requestFilters || $responseFilters || $responseAction !== RuleAction::Allow) {
                throw new \Exception('A firewall rule with Deny request action can never fulfill request filters, response filters or response actions');
            }
            if ($requestMatcher instanceof AlwaysMatcher) {
                $this->warning('A firewall rule with Deny request action and matching all requests is a bad smell. The firewall default is to block all requests not matching any rule...');
            }
        }
        if ($responseAction === RuleAction::Deny) {
            if ($responseFilters) {
                throw new \Exception('A firewall rule with Deny response action can never fulfill response filters');
            }
            if ($responseMatcher instanceof AlwaysMatcher) {
                $this->warning('A firewall rule with Deny response action and matching all responses is a bad smell. Are you sure you did not mean to deny the request instead?');
            }
        }

        $this->requestMatcher = $requestMatcher;
        $this->requestFilters = $requestFilters;
        $this->requestAction = $requestAction;
        $this->responseMatcher = $responseMatcher;
        $this->responseFilters = $responseFilters;
        $this->responseAction = $responseAction;
    }

    public function getRequestAction(): RuleAction
    {
        return $this->requestAction;
    }

    public function getResponseAction(): RuleAction
    {
        return $this->responseAction;
    }

    public function matches(...$items): bool
    {
        if (count($items) !== 1 || ! $items[0] instanceof ServerRequestInterface) {
            throw new \InvalidArgumentException('Rule expected a ServerRequestInterface to match but got instead a ' . gettype($items[0]));
        }

        return $this->matchesRequest($items[0]);
    }

    public function matchesRequest(ServerRequestInterface $request): bool
    {
        return $this->requestMatcher->matchesRequest($request);
    }

    /**
     * @throws RequestDenied
     */
    public function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
    {
        if ($this->requestAction === RuleAction::Deny) {
            throw new RequestDenied();
        }

        foreach ($this->requestFilters as $requestFilter) {
            $request = $requestFilter->filterServerRequest($request);
            if ($request instanceof ResponseInterface) {
                return $request;
            }
        }
        return $request;
    }

    protected function matchesResponse(ResponseInterface $response): bool
    {
        return (bool)$this->responseMatcher?->matchesResponse($response);
    }

    /**
     * @throws RequestDenied
     */
    public function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        if ($this->matchesResponse($response)) {
            if ($this->responseAction === RuleAction::Deny) {
                throw new RequestDenied();
            }
            foreach ($this->responseFilters as $responseFilter) {
                $response = $responseFilter->filterResponse($response, $request);
            }
        }
        return $response;
    }
}
