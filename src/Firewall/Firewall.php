<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Firewall;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\RequestDenied;
use TanoWAF\WAFCore\Http\BodyUncompressingCapableInterface;
use TanoWAF\WAFCore\Http\HeaderParsingCapableInterface;
use TanoWAF\WAFCore\Http\InspectableMessageInterface;
use TanoWAF\WAFCore\Logger\PrivateLoggerTrait;
//use TanoWAF\WAFCore\Response\Psr7\Response;
use TanoWAF\WAFCore\Response\Psr7\ResponseConverterInterface;
use TanoWAF\WAFCore\ServerRequest\Psr7\ServerRequestConverterInterface;
use TanoWAF\WAFCore\Stdlib;

/**
 * The class doing the actual filtering of Requests and Responses
 */
class Firewall implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use PrivateLoggerTrait;
    protected ServerRequestConverterInterface $requestFactory;
    protected ResponseConverterInterface $responseFactory;

    /** @var Rule[] */
    protected array $rules;
    /** @var Rule[] */
    protected array $matchingRules;

    /**
     * @param Rule[] $rules
     * @throws \InvalidArgumentException
     */
    public function __construct(array $rules, ServerRequestConverterInterface $requestFactory, ResponseConverterInterface $responseFactory, LoggerInterface|null $logger = null)
    {
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
        $this->logger = $logger;
        if (!Stdlib::array_of($rules, Rule::class)) {
            throw new \InvalidArgumentException("Array passed to " . static::class . " constructor must contain only instances of " . Rule::class);
        }
        /// @todo remove this warning if implementing an `addRule` method
        if (!$rules) {
            $this->warning("Firewall was set up with no rules. This is most likely not what you wanted...");
        }
        $this->rules = $rules;
    }

    /**
     * @throws RequestDenied
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $request instanceof InspectableMessageInterface) {
            $request = $this->requestFactory->fromServerRequest($request);
        }

        $request = $this->filterServerRequest($request);
        if ($request instanceof ResponseInterface) {
            return $request;
        }

        $response = $this->forwardRequest($request, $handler);

/// @todo should we send the original request to filterResponse() ??? (possibly cloned, have to check immutability...)
        return $this->filterResponse($response, $request);
    }

    /**
     * @throws RequestDenied
     */
    protected function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
    {
        $this->matchingRules = [];
        foreach ($this->rules as $ruleName => $rule) {
            if ($rule->matchesRequest($request)) {
/// @todo... be more specific in the log line: mention the matcher too
                $this->debug("Firewall rule '$ruleName' matched request: " . $this->request2Log($request));

                if ($rule->getRequestAction() === RuleAction::Deny) {
                    // no need to run the filter part of the rule
                    throw new RequestDenied("Access denied by rule '$ruleName'");
                }

                $this->matchingRules[] = $rule;
                $request = $rule->filterServerRequest($request);
                if ($request instanceof ResponseInterface) {
                    return $request;
                }

/// @todo... handle other (future) cases
                switch ($rule->getRequestAction()) {
                    case RuleAction::Allow:
                        return $request;
                    //case RuleAction::Deny:
                    //    return $request;
                }
            }
        }

        $this->debug("No firewall rule matched request: " . $this->request2Log($request));
        throw new RequestDenied();
    }

    /// @todo either typehint to our own Response, or create an interface for ResponseInterface + HeaderParsingCapableInterface + BodyUncompressingCapableInterface
    protected function forwardRequest(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (! $response instanceof InspectableMessageInterface) {
            $response = $this->responseFactory->fromResponse($response);
            /*$response = Response::fromResponse($response);
            if ($this->cookieParser !== null) {
                $response->setCookieParser($this->cookieParser);
            }
            if ($this->headerParser !== null) {
                $response->setHeaderParser($this->headerParser);
            }*/
        }

        return $response;
    }

    /**
     * @throws RequestDenied
     */
    protected function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        try {
            for ($i = count($this->matchingRules) - 1; $i >= 0; $i--) {
                $response = $this->matchingRules[$i]->filterResponse($response, $request);
            }
        } finally {
            // in case someone has the bad idea of calling filterResponse twice in a row
            $this->matchingRules = [];
        }
        return $response;
    }

    // *** Logging ***

    protected function request2Log(ServerRequestInterface $request): string
    {
        return $request->getMethod() . ' ' . $request->getUri();
    }
}
