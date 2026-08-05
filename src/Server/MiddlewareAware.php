<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Server;

use Nyholm\Psr7\Response;
//use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\RequestDenied;
use TanoWAF\WAFCore\Exception\UpstreamRequestError;
use TanoWAF\WAFCore\Exception\UpstreamRequestTimeout;
use TanoWAF\WAFCore\Logger\PrivateLoggerTrait;
use TanoWAF\WAFCore\Proxy\Proxy;

/**
 * Allows adding middlewares to execute logic before forwarding the request / after having received the response,
 * such as e.g. a firewall middleware component.
 */
abstract class MiddlewareAware implements RequestHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use PrivateLoggerTrait;

    protected MiddlewareInterface $filter;
    protected RequestHandlerInterface $upstreamConnector;

    public function __construct(MiddlewareInterface $filter, RequestHandlerInterface $upstreamConnector, LoggerInterface|null $logger = null)
    {
        $this->filter = $filter;
        $this->upstreamConnector = $upstreamConnector;
        $this->logger = $logger;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->debug("Received request: " . $this->request2Log($request));

            $response = $this->filter->process($request, $this->upstreamConnector);
            $this->debug("Returned response: " . $this->response2Log($response));
        } catch (RequestDenied $e) {
            $this->debug("Request Denied Exception thrown during processing of request" . (($msg = $e->getMessage()) !== '' ? (': ' . $msg) : ''));
            $response = $this->deniedResponse($request, $e);
        } catch (UpstreamRequestTimeout $e) {
            $response = $this->upstreamTimeoutResponse($request, $e);
        } catch (UpstreamRequestError $e) {
            $response = $this->upstreamErrorResponse($request, $e);
        } catch (\Throwable $e) {
            $this->error("Exception thrown during processing of request" . (($msg = $e->getMessage()) !== '' ? (': ' . $msg) : ''));
            // NB: we do not catch exceptions thrown during this function call as we would not know what to return anyway...
            $response = $this->errorResponse($request, $e);
        }

        // We should never send a body back to HEAD requests. Be lenient of upstreams and access denied errors
        // Hopefully this does not modify the content-type header...
/// @todo... we could/should move this to a 'drop-body-for-head-responses' middleware / fw rule... but what if that middleware
///          gets short-circuited by another middleware in the chain throwing an exception?
///          A solution could be to move the try/catch block in its own middleware (inception! :-D)
        /*
        if ($request->getMethod() === 'HEAD') {
            /// @todo we could log a warning if upstream sent a body, but that would force us to read it fully, so
            ///       we don't do that to save resources
            $response = $response->withBody(Stream::create());
        }*/

        return $response;
    }

    /**
     * To be overridden in subclasses if needed
     */
    protected function upstreamErrorResponse(ServerRequestInterface $request, \Throwable|null $e = null): ResponseInterface
    {
        return new Response(Proxy::UPSTREAM_ERROR_STATUS_CODE);
    }

    /**
     * To be overridden in subclasses if needed
     */
    protected function upstreamTimeoutResponse(ServerRequestInterface $request, \Throwable|null $e = null): ResponseInterface
    {
        return new Response(Proxy::UPSTREAM_TIMEOUT_STATUS_CODE);
    }

    /**
     * Generates an "access denied" response.
     * Make sure to mimic what the upstream API returns by default for not-accepted requests - but give a specific hint
     * so that these responses can be told apart from the upstream's "access denied" ones.
     * Also, it is a good idea to ask $upstreamConnector for data to add to the `via` header of the generated response.
     * @todo make it easy to set this response via configuration. Allow eg. a string+content-type, and filename+content-type
     */
    abstract protected function deniedResponse(ServerRequestInterface $request, \Throwable|null $e = null): ResponseInterface;

    /**
     * Generates an "error happened" response.
     * Make sure to mimic correctly what the upstream API returns by default for failed requests - but give a specific hint
     * so that these responses can be told apart from the upstream's "error happened" ones.
     * Also, it is a good idea to ask $upstreamConnector for data to add to the `via` header of the generated response.
     * @todo make it easy to set this response via configuration. Allow eg. a string+content-type, and filename+content-type
     */
    abstract protected function errorResponse(ServerRequestInterface $request, \Throwable|null $e = null): ResponseInterface;

    // *** Logging ***

    protected function request2Log(ServerRequestInterface $request): string
    {
        return $request->getMethod() . ' ' . $request->getUri();
    }

    protected function response2Log(ResponseInterface $response): string
    {
        return $response->getStatusCode() . ' ' . $response->getReasonPhrase();
    }
}
