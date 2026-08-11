<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Proxy;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\RequestDenied;
use TanoWAF\WAFCore\Exception\UpstreamRequestError;
use TanoWAF\WAFCore\Exception\UpstreamRequestTimeout;
use TanoWAF\WAFCore\Logger\PrivateLoggerTrait;
use TanoWAF\WAFCore\Tracer\RequestTracerTrait;
use TanoWAF\WAFCore\UpstreamClient\UpstreamClientFactory;
use TanoWAF\WAFCore\UpstreamClient\UpstreamClientInterface;

class Proxy implements ProxyInterface, LoggerAwareInterface
{
    const UPSTREAM_ERROR_STATUS_CODE = 502;
    const UPSTREAM_TIMEOUT_STATUS_CODE = 504;

    use LoggerAwareTrait;
    use PrivateLoggerTrait;
    use RequestTracerTrait;

    protected UpstreamClientInterface $client;
    protected array $overrideHeaders = [];
    protected array $overriddenHeaders = [];
    protected string $viaHeaderPseudonym = 'WAFCore';
    /// enable this to let the proxy answer to TRACE requests with Max-Forwards=0 if asked to
    protected bool $answerTraceRequests = false;
    /// NB: only used in answers to OPTIONS requests. This is not a list used to drop incoming requests! TRACE gets added dynamically
    /// @todo add CONNECT after we add support for it
    protected array $allowedMethods = ['DELETE', 'GET', 'HEAD', 'PATCH', 'POST', 'PUT'];
    protected string $userAgent = 'WAFCore Proxy HttpClient';
    /// used by the RequestTracerTrait
    private string $requestPrefix = '';

    /**
     * @todo fold the $logger arg into the options?
     * @todo what about unifying the arrays of options for $this and for the $httpClient?
     * @todo
     * @throws \Exception
     */
    public function __construct(UpstreamClientInterface|array|null $httpClient = null, LoggerInterface|null $logger = null)
    {
        // set first the logger
        $this->logger = $logger;
        if ($httpClient === null || is_array($httpClient)) {
            $httpClient = (new UpstreamClientFactory())->createClient((array)$httpClient);
        }
        $this->client = $httpClient;
        $this->overrideHeaders['User-Agent'] = $this->userAgent . (
            ($cua = $this->$this->client->getUserAgent()) !== '' ? ' (' . $cua . ')' : ''
        );
    }

    /**
     * @throws RequestDenied when using a middleware-aware client, this could be thrown
     * @throws UpstreamRequestError
     * @throws UpstreamRequestTimeout
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $request = $this->filterRequest($request);
        if ($request instanceof ResponseInterface) {
            return $request;
        }

/// @todo... we should follow the rules set out in https://httpwg.org/specs/rfc9112.html#rfc.section.3.2.2: use the
///          host/port from the absolute form of the uri to replace the value from Host header

        $response = $this->sendRequest($this->client, $request);

        return $this->filterResponse($response, $request);
    }

    /**
     * Aka "handleInner".
     * NB: when $client is async, this might not throw at all, but exceptions might be thrown when trying to read
     * the response body later...
     *
     * @throws RequestDenied when using a middleware-aware client, this could be thrown
     * @throws UpstreamRequestError
     * @throws UpstreamRequestTimeout NB: only if a timeout was set into $client
     */
    protected function sendRequest(UpstreamClientInterface $client, ServerRequestInterface $request): ResponseInterface
    {
        try {
            $response = $client->sendRequest($request);

            $this->debug("Upstream returned HTTP/" . $response->getProtocolVersion() . ' ' . $response->getStatusCode() . ' ' .
                $response->getReasonPhrase());
            $response = $response->withAddedHeader('Via', $this->getViaHeader($request));
        } catch (RequestDenied $e) {
            $this->debug("Request denied before sending to upstream: " . $e->getMessage());
            throw $e;
        } catch (UpstreamRequestTimeout $e) {
            $this->debug("Timeout sending request to upstream: " . $e->getMessage());
            throw $e;
        } catch (UpstreamRequestError $e) {
            $this->debug("Error sending request to upstream: " . $e->getMessage());
            throw $e;
        } catch (NetworkExceptionInterface $e) {
            $this->debug("Network error sending request to upstream (" . get_class($e) . "): " . $e->getMessage());
            throw new UpstreamRequestError($e->getMessage(), $e->getCode(), $e);
        } catch (\Throwable $e) {
            $this->debug("Unexpected error sending request to upstream (" . get_class($e) . "): " . $e->getMessage());
            throw new UpstreamRequestError($e->getMessage(), $e->getCode(), $e);
        }

        return $response;
    }

    /**
     * @throws RequestDenied
     */
    protected function filterRequest(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
    {
/// @todo... make sure we avoid infinite loops by sending requests to self (either here or?)

        // handle 'Max-Forwards'
        $requestMethod = $request->getMethod();
        if (($requestMethod === 'OPTIONS' || $requestMethod === 'TRACE') && $request->hasHeader('Max-Forwards')) {
            $mc = $request->getHeader('Max-Forwards')[0];
            /// @todo should we relax the constraint on 'only 1 digit'?
            if (ctype_digit($mc) && strlen($mc) === 1) {
                if ($mc === '0') {
                    return $this->answerRequestDirectly($request);
                } else {
                    $request = $request->withHeader('Max-Forwards', intval($mc) - 1);
                }
            }
        }

/// @todo... add x-forwarded headers and co., strip/massage _all_ hop-by-hop headers

        // honour the Connection header
        if ($request->hasHeader('Connection')) {
            foreach($request->getHeader('Connection') as $header) {
                if ($request->hasHeader($header)) {
                    $request = $request->withoutHeader($header);
                }
            }
            $request = $request->withoutHeader('Connection');
        }
        // and remove as well headers that are known to only pertain to the connection between the client and us
        foreach($this->clientHeadersNotForUpstream() as $header) {
            if ($request->hasHeader($header)) {
                $request = $request->withoutHeader($header);
            }
        }

        $this->overriddenHeaders = [];
        foreach ($this->overrideHeaders as $name => $value) {
            if ($request->hasHeader($name)) {
                $this->overriddenHeaders[$name] = $request->getHeader($name);
            }
            $request = $request->withHeader($name, $value);
        }

        $request = $request->withAddedHeader('Via', $this->getViaHeader($request));

        return $request;
    }

    protected function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        return $response;
    }

    /**
     * @throws RequestDenied
     */
    protected function answerRequestDirectly(RequestInterface $request): ResponseInterface
    {
        switch ($request->getMethod()) {
            case 'OPTIONS':
                return new Response(
                    204,
                    ['Allow' => $this->getAllowedmethods()],
                    '',
                    $request->getProtocolVersion()
                );
            case 'TRACE':
                if ($this->answerTraceRequests) {
                    return new Response(
                        200,
                        ['Content-Type' => 'message/http'],
                        // as per the spec, TRACE reqs should not have a body. But, in case they do, we reset it
                        /// @todo... is there a better way than this to remove the body?
                        ///          Should we clone the request - but not its body?
                        ///          Should we use a bespoke null-stream implementation?
                        $this->serializeRequest($request->withBody(Stream::create())),
                        $request->getProtocolVersion()
                    );
                }
                throw new RequestDenied("TRACE requests are not supported by the proxy");
            default:
                // Throw, let the middleware catch this and send an appropriate response (this should e a 500 error, really)
                throw new \InvalidArgumentException("Unexpected call to answer directly to a " . $request->getMethod() . " request");
        }
    }

    /**
     * Override this if you prefer to have host:port, a version nr. in the pseudonym,  or any other compliant string.
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Via
     */
    public function getViaHeader(ServerRequestInterface $request): string
    {
        return $request->getProtocolVersion() . ' ' . $this->viaHeaderPseudonym;
    }

    /**
     * @return string[]
     */
    protected function getAllowedmethods(): array
    {
        $methods = $this->allowedMethods;
        if ($this->answerTraceRequests) {
            $methods[] = 'TRACE';
        }
        return array_unique($methods);
    }

    protected function clientHeadersNotForUpstream(): array
    {
        return ['Keep-Alive', 'Proxy-Connection', 'TE', 'Transfer-Encoding', 'Upgrade'];
    }
}
