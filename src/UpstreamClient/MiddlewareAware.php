<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\UpstreamClient;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Filter\Bidirectional\ClientBidirectionalFilterInterface;
use TanoWAF\WAFCore\Logger\PrivateLoggerTrait;

class MiddlewareAware implements UpstreamClientInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use PrivateLoggerTrait;

    protected ClientBidirectionalFilterInterface $filter;
    protected UpstreamClientInterface $upstreamClient;

    public function __construct(ClientBidirectionalFilterInterface $filter, UpstreamClientInterface $upstreamClient, LoggerInterface|null $logger = null)
    {
        $this->filter = $filter;
        $this->upstreamClient = $upstreamClient;
        $this->logger = $logger;
    }

    /**
     * @throws \Psr\Http\Client\ClientExceptionInterface possibly thrown by other adapters than the Guzzle, Symfony ones
     * @throws \TanoWAF\WAFCore\Exception\RequestDenied
     * @throws \TanoWAF\WAFCore\Exception\UpstreamRequestError
     * @throws \TanoWAF\WAFCore\Exception\UpstreamRequestTimeout
     *
* @todo... we should probably wrap ClientExceptionInterface (and basically every other error but for UpstreamRequestError, UpstreamRequestTimeout) into an UpstreamRequestError?
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $request = $this->filter->filterClientRequest($request);
        if ($request instanceof ResponseInterface) {
            return $request;
        }
        $response = $this->upstreamClient->sendRequest($request);
/// @todo should we pass the original or modified request ??? (possibly cloning it)
        return $this->filter->filterResponse($response, $request);
    }

    public function withOptions(array $options): UpstreamClientInterface
    {
        $clone = clone $this;
        $clone->upstreamClient = $clone->upstreamClient->withOptions($options);
        return $clone;
    }

    public function getUserAgent(): string
    {
        return $this->upstreamClient->getUserAgent();
    }
}
