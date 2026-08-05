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
use TanoWAF\WAFCore\Logger\PrivateLoggerTrait;
use TanoWAF\WAFCore\Stdlib;

/**
 * The class doing the actual filtering of Requests and Responses
 */
class Firewall implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use PrivateLoggerTrait;

    /** @var Rule[] */
    protected array $rules;
    protected null|Rule $currentRule = null;

    /**
     * @param Rule[] $rules
     * @throws \InvalidArgumentException
     */
    public function __construct(array $rules, LoggerInterface|null $logger = null)
    {
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
        $request = $this->filterServerRequest($request);
        $response = $handler->handle($request);
/// @todo should we send the original request to filterResponse() ??? (possibly cloned, have to check immutability...)
        return $this->filterResponse($response, $request);
    }

    /**
     * @throws RequestDenied
     */
    protected function filterServerRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $this->currentRule = null;
        foreach ($this->rules as $ruleName => $rule) {
            if ($rule->matchesRequest($request)) {
                $this->debug("Firewall rule '$ruleName' matched request: " . $this->request2Log($request));
                $this->currentRule = $rule;
                return $rule->filterServerRequest($request);
            }
        }

        $this->debug("No firewall rule matched request: " . $this->request2Log($request));
        throw new RequestDenied();
    }

    /**
     * @throws RequestDenied
     */
    protected function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->currentRule->filterResponse($response, $request);
        $this->currentRule = null;
        return $response;
    }

    // *** Logging ***

    protected function request2Log(ServerRequestInterface $request): string
    {
        return $request->getMethod() . ' ' . $request->getUri();
    }
}
