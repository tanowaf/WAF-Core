<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr7;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * A reimplementation of Nyholm\Psr7Server\ServerRequestCreator, attempting to suit better the forward-proxy use-case and
 * fixing a few known bugs (see https://github.com/Nyholm/psr7-server/issues).
 *
 * Note that this is de facto a ServerRequest Factory. Alas, the Psr17 ServerRequestFactoryInterface is not quite
 * appropriate nor sufficient for when building a ServerRequest out of the data php receives from (standard) web servers
 * via the SAPI modules...
 *
 * @see https://github.com/Nyholm/psr7-server/issues/65, https://github.com/Nyholm/psr7-server/issues/62, https://github.com/Nyholm/psr7-server/pull/49
 *
 * @todo add support for trusted proxies in front of us: allow whitelisting their IPs and the headers such as x-forwarded-...
 * @todo... fold into ServerRequestFactory?
 */
class Creator
{
    protected ServerRequestFactoryInterface $serverRequestFactory;

    protected UriFactoryInterface $uriFactory;

    /**
     * NB: waf-core changes:
     * - signature changed compared to the original
     * - the ServerRequestFactoryInterface passed in is expected to build the request fully from superglobals like $_POST
     *   and $_FILES besides the passed-in `$server` (and possibly prefer its own parsing of `$uri` and `$server`
     *   for building the equivalent of $_GET, $_COOKIE)
     */
    public function __construct(
        UriFactoryInterface $uriFactory,
        ServerRequestFactoryInterface $serverRequestFactory,
    ) {
        $this->uriFactory = $uriFactory;
        $this->serverRequestFactory = $serverRequestFactory;
    }

    public function fromGlobals(): ServerRequest
    {
        $server = $_SERVER;

        $requestAttributes = new Attributes();

        if (isset($server['REQUEST_METHOD']) === false) {
            $method = 'GET';
            $requestAttributes->set(Attributes::REQUEST_METHOD_SYNTHETIC, true);
        } else {
            $method = $server['REQUEST_METHOD'];
        }

        // waf-core change: expanded getUriFromEnvWithHTTP inline in createUriFromArray, so we can add an attribute in case uri scheme is missing
        //$uri = $this->getUriFromEnvWithHTTP($server);
        $uri = $this->createUriFromArray($server, $requestAttributes);

        $request = $this->serverRequestFactory->createServerRequest($method, $uri, $server);

        /** @var Attributes $ra */
        if (($ra = $request->getAttribute(Attributes::class)) === null) {
            $request = $request->withAttribute(Attributes::class, $requestAttributes);
        } else {
            foreach ($requestAttributes->keys() as $key) {
                $ra->set($key, $requestAttributes->get($key));
            }
        }

        return $request;
    }

    /**
     * Create a new uri from server variables.
     * NB: eschews access to $_GET.
     * NB: trusts the Host header over SERVER_PORT, SERVER_NAME
     *
     * @param array $server typically $_SERVER or similar structure
     */
    private function createUriFromArray(array $server, Attributes $requestAttributes): UriInterface
    {
        $uri = $this->uriFactory->createUri('');

        // waf-core change: do not trust X-FORWARDED-PROTO. We have to add 1st support for trusted proxies IPs
        /// @see https://github.com/Nyholm/psr7-server/issues/63
        //if (isset($server['HTTP_X_FORWARDED_PROTO'])) {
        //    $uri = $uri->withScheme($server['HTTP_X_FORWARDED_PROTO']);
        //} else {

/// @todo... there is at least one user mentioning having HTTPS=on and REQUEST_SCHEME=http... See issue #54
            if (isset($server['REQUEST_SCHEME'])) {
                $uri = $uri->withScheme($server['REQUEST_SCHEME']);
            } elseif (isset($server['HTTPS'])) {
                $uri = $uri->withScheme('on' === $server['HTTPS'] ? 'https' : 'http');
            } else {
                // waf-core change: inlined here from getUriFromEnvWithHTTP
                $uri = $uri->withScheme('http');
                $requestAttributes->set(Attributes::URI_SCHEME_SYNTHETIC, true);
            }
        //}

        if (false !== ($haveHostHeader = isset($server['HTTP_HOST']))) {
            if (1 === \preg_match('/^(.+):(\d+)$/', $server['HTTP_HOST'], $matches)) {
                // waf-core change: fix issue #52 by casting to int
                $uri = $uri->withHost($matches[1])->withPort((int)$matches[2]);
            } else {
                // waf-core change: in case the Host header misses a port, we consider that it uses the default port for
                // the current scheme instead of the one from $server['SERVER_PORT']
                $uri = $uri->withHost($server['HTTP_HOST']);
            }
        } else {
            $requestAttributes->set(Attributes::MISSING_HOST_HEADER, true);
        }

        // waf-core change: $server['SERVER_PORT'] can be set and empty when the server is listening on a unix socket
        if (isset($server['SERVER_PORT']) && $server['SERVER_PORT'] !== '') {
            if (!$haveHostHeader) {
                // waf-core change: fix issue #52 by casting to int
                $uri = $uri->withPort((int)$server['SERVER_PORT']);
                $requestAttributes->set(Attributes::URI_PORT_SYNTHETIC, true);
            }
            $requestAttributes->set(Attributes::SERVER_PORT, $server['SERVER_PORT']);
        }
        if (isset($server['SERVER_NAME'])) {
            if (!$haveHostHeader) {
                $uri = $uri->withHost($server['SERVER_NAME']);
                $requestAttributes->set(Attributes::URI_HOST_SYNTHETIC, true);
            }
            $requestAttributes->set(Attributes::SERVER_NAME, $server['SERVER_NAME']);
        }

        if (isset($server['REQUEST_URI'])) {
            // waf-core change: fix issue #66. On Apache, when requests are received with an absolute uri as target,
            // $_SERVER['REQUEST_URI'] will indeed be the absolute uri. Sadly other webservers do not share this behaviour...
/// @todo... tag the request with the absolute uri, if $server['REQUEST_URI'] is in fact such.
///          Also, check carefully the HTTP spec to see what is says about precedence of the Host header vs the absolute url
            $parts = parse_url($server['REQUEST_URI']);
            $uri = $uri->withPath($parts['path']);
            if (isset($parts['host'])) {
                $requestAttributes->set(Attributes::ABSOLUTE_REQUEST_URI, $server['REQUEST_URI']);
            }

            // NB: we do _not_ have to handle the fragment part here, as that is in fact handled purely in-browser
        } else {
            $requestAttributes->set(Attributes::MISSING_REQUEST_URI, true);
        }

        if (isset($server['QUERY_STRING'])) {
            $uri = $uri->withQuery($server['QUERY_STRING']);
        }

        return $uri;
    }
}
