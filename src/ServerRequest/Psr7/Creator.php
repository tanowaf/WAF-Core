<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr7;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use TanoWAF\WAFCore\ServerRequest\Psr17\ExtendedFactoryInterface;
use TanoWAF\WAFCore\ServerRequest\Psr17\Factory as ServerRequestFactory;
use TanoWAF\WAFCore\Stdlib;

/**
 * A reimplementation of Nyholm\Psr7Server\ServerRequestCreator, attempting to suit better the forward-proxy use-case and
 * fixing a few known bugs (see https://github.com/Nyholm/psr7-server/issues).
 *
 * Note that this is de facto a ServerRequest Factory. Alas, the Psr17 ServerRequestFactoryInterface is not quite
 * appropriate nor sufficient for when building a ServerRequest out of the data php receives from the web server...
 *
 * @todo add support for trusted proxies in front of us: allow whitelisting their IPs and the headers such as x-forwarded-...
 *
 * @see https://github.com/Nyholm/psr7-server/issues/65, https://github.com/Nyholm/psr7-server/issues/62, https://github.com/Nyholm/psr7-server/pull/49
 */
class Creator
{
    protected ServerRequestFactoryInterface|null $serverRequestFactory;

    protected UriFactoryInterface $uriFactory;

    protected UploadedFileFactoryInterface $uploadedFileFactory;

    protected StreamFactoryInterface $streamFactory;

    /**
     * NB: yawaf change: signature changed compared to the original!
     */
    public function __construct(
        UriFactoryInterface $uriFactory,
        UploadedFileFactoryInterface $uploadedFileFactory,
        StreamFactoryInterface $streamFactory,
        ServerRequestFactoryInterface|null $serverRequestFactory = null,
    ) {
        $this->uriFactory = $uriFactory;
        $this->uploadedFileFactory = $uploadedFileFactory;
        $this->streamFactory = $streamFactory;
        if ($serverRequestFactory === null) {
            $serverRequestFactory = new ServerRequestFactory();
        }
        $this->serverRequestFactory = $serverRequestFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function fromGlobals(): ServerRequestInterface
    {
        $server = $_SERVER;
        if (($haveRequestMethod = isset($server['REQUEST_METHOD'])) === false) {
            $server['REQUEST_METHOD'] = 'GET';
        }

        // yawaf change: never call 'getallheaders' - we allow callers to monkey-patch $_SERVER
        $headers = Stdlib::getHeadersFromServer($server);

        $post = null;
        if ('POST' === $server['REQUEST_METHOD']) {
            foreach ($headers as $headerName => $headerValue) {
                if (true === \is_int($headerName) || 'content-type' !== \strtolower($headerName)) {
                    continue;
                }
                if (\in_array(
                    \strtolower(\trim(\explode(';', $headerValue, 2)[0])),
                    ['application/x-www-form-urlencoded', 'multipart/form-data']
                )) {
                    $post = $_POST;

                    break;
                }
            }
            /// @todo consistency check: if $_POST is not empty, and 'content-type' header is not one of the expected 2, tag the response
        }

        $request = $this->fromArrays($server, $headers, $_COOKIE, $_GET, $post, $_FILES, \fopen('php://input', 'r') ?: null);
        // yawaf change: add attribute
        if (!$haveRequestMethod) {
            $request->getAttribute(Attributes::class)?->set(Attributes::REQUEST_METHOD_SYNTHETIC, true);
        }
        return $request;
    }

    /**
     * {@inheritdoc}
     * @todo see the logic in Symfony\Component\HttpFoundation\Request::createFromGlobals for comparison
     */
    public function fromArrays(array $server, array $headers = [], array $cookie = [], array $get = [], ?array $post = null, array $files = [], $body = null): ServerRequestInterface
    {
        $requestAttributes = new Attributes();

        $method = $server['REQUEST_METHOD'];

        // yawaf change: expanded getUriFromEnvWithHTTP inline in createUriFromArray, so we can add an attribute in case uri scheme is missing
        //$uri = $this->getUriFromEnvWithHTTP($server);
        $uri = $this->createUriFromArray($server, $requestAttributes);

        if (false === isset($server['SERVER_PROTOCOL'])) {
            $protocol = '1.1';
            $requestAttributes->set(Attributes::SERVER_PROTOCOL_SYNTHETIC, true);
        } else {
            $protocol =\str_replace('HTTP/', '', $server['SERVER_PROTOCOL']);
        }

        /// @todo analyze and reconcile differences between $_GET and $server['QUERY_STRING'], as well as between
        ///       $_COOKIE and $headers['cookie'], save them as attributes in the request?

        // yawaf change: Psr17Factory::createServerRequest misses the ability of ServerRequest::__construct to work off
        // headers. That in turn requires more work immediately afterwards to patch in the headers, except for the
        // Host one. So we go straight for ServerRequest::__construct instead
        if ($this->serverRequestFactory instanceof ExtendedFactoryInterface) {
            $serverRequest = $this->serverRequestFactory->createServerRequestEx($method, $uri, $server, $headers, $protocol);
        } else {

            $serverRequest = $this->serverRequestFactory->createServerRequest($method, $uri, $server);

            foreach ($headers as $name => $value) {
                // Because PHP automatically casts array keys set with numeric strings to integers, we have to make sure
                // that numeric headers will not be sent along as integers, as withAddedHeader can only accept strings.
                if (\is_int($name)) {
                    $name = (string) $name;
                }

                // yawaf change: handle the case where the request already has an `host` header
                // We prefer the 'host' header received from the server to the one rebuilt from the uri - even though
                // in reality they are both built off the same thing!
                // NB: this works best when assuming that there is a single HTTP_HOST in $_SERVER_. That is part of the http
                // spec, so we trust the webserver to enforce it for us (note that some webservers might concatenate multiple
                // host headers in a single, csv-formatted value)
                if ($name === 'host' && $serverRequest->hasHeader('host')) {
                    $serverRequest = $serverRequest->withoutHeader('host');
                }
                $serverRequest = $serverRequest->withAddedHeader($name, $value);
            }

            $serverRequest = $serverRequest->withProtocolVersion($protocol);

        }

        $serverRequest = $serverRequest
            ->withCookieParams($cookie)
            ->withQueryParams($get)
            ->withParsedBody($post)
            ->withUploadedFiles($this->normalizeFiles($files));

        if (isset($server['REMOTE_ADDR'])) {
            $requestAttributes->set(Attributes::REMOTE_ADDR, $server['REMOTE_ADDR']);
        }
        if (isset($server['REMOTE_PORT'])) {
            $requestAttributes->set(Attributes::REMOTE_PORT, $server['REMOTE_PORT']);
        }

        // yawaf change: add an attribute bag to the request
        $serverRequest = $serverRequest->withAttribute(Attributes::class, $requestAttributes);

        if (null === $body) {
            return $serverRequest;
        }

        if (\is_resource($body)) {
            $body = $this->streamFactory->createStreamFromResource($body);
        } elseif (\is_string($body)) {
            $body = $this->streamFactory->createStream($body);
        } elseif (!$body instanceof StreamInterface) {
            throw new \InvalidArgumentException('The $body parameter to ServerRequest\Creator::fromArrays must be string, resource or StreamInterface');
        }

        return $serverRequest->withBody($body);
    }

    // yawaf change: removed, since we inject 'REQUEST_METHOD' into $server if it is not there in the 1st place
    /*private function getMethodFromEnv(array $environment): string
    {
        if (false === isset($environment['REQUEST_METHOD'])) {
            throw new \InvalidArgumentException('Cannot determine HTTP method');
        }

        return $environment['REQUEST_METHOD'];
    }*/

    /*private function getUriFromEnvWithHTTP(array $environment): UriInterface
    {
        $uri = $this->createUriFromArray($environment);
        if (empty($uri->getScheme())) {
            $uri = $uri->withScheme('http');
        }

        return $uri;
    }*/

    /**
     * Return an UploadedFile instance array.
     *
     * @param array $files An array which respect $_FILES structure
     *
     * @return UploadedFileInterface[]
     *
     * @throws \InvalidArgumentException for unrecognized values
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
            } elseif (\is_array($value) && isset($value['tmp_name'])) {
                $normalized[$key] = $this->createUploadedFileFromSpec($value);
            } elseif (\is_array($value)) {
                $normalized[$key] = $this->normalizeFiles($value);
            } else {
                throw new \InvalidArgumentException('Invalid value in files specification');
            }
        }

        return $normalized;
    }

    /**
     * Create and return an UploadedFile instance from a $_FILES specification.
     *
     * If the specification represents an array of values, this method will
     * delegate to normalizeNestedFileSpec() and return that return value.
     *
     * @param array $value $_FILES struct
     */
    private function createUploadedFileFromSpec(array $value): array|UploadedFileInterface
    {
        if (\is_array($value['tmp_name'])) {
            return $this->normalizeNestedFileSpec($value);
        }

/// @todo... look into issue #59: we should probably call is_uploaded_file($value['tmp_name']) around here

        if (UPLOAD_ERR_OK !== $value['error']) {
            $stream = $this->streamFactory->createStream();
        } else {
            try {
                $stream = $this->streamFactory->createStreamFromFile($value['tmp_name']);
            } catch (\RuntimeException $e) {
                $stream = $this->streamFactory->createStream();
            }
        }

        return $this->uploadedFileFactory->createUploadedFile(
            $stream,
            (int) $value['size'],
            (int) $value['error'],
            $value['name'],
            $value['type']
        );
    }

    /**
     * Normalize an array of file specifications.
     *
     * Loops through all nested files and returns a normalized array of
     * UploadedFileInterface instances.
     *
     * @return UploadedFileInterface[]
     */
    private function normalizeNestedFileSpec(array $files = []): array
    {
        $normalizedFiles = [];

        foreach (\array_keys($files['tmp_name']) as $key) {
            $spec = [
                'tmp_name' => $files['tmp_name'][$key],
                'size' => $files['size'][$key],
                'error' => $files['error'][$key],
                'name' => $files['name'][$key],
                'type' => $files['type'][$key],
            ];
            $normalizedFiles[$key] = $this->createUploadedFileFromSpec($spec);
        }

        return $normalizedFiles;
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

        // yawaf change: do not trust X-FORWARDED-PROTO. We have to add 1st support for trusted proxies IPs
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
                // yawaf change: inlined here from getUriFromEnvWithHTTP
                $uri = $uri->withScheme('http');
                $requestAttributes->set(Attributes::URI_SCHEME_SYNTHETIC, true);
            }
        //}

        if (false !== ($haveHostHeader = isset($server['HTTP_HOST']))) {
            if (1 === \preg_match('/^(.+)\:(\d+)$/', $server['HTTP_HOST'], $matches)) {
                // yawaf change: fix issue #52 by casting to int
                $uri = $uri->withHost($matches[1])->withPort((int)$matches[2]);
            } else {
                // yawaf change: in case the Host header misses a port, we consider that it uses the default port for
                // the current scheme instead of the one from $server['SERVER_PORT']
                $uri = $uri->withHost($server['HTTP_HOST']);
            }
        } else {
            $requestAttributes->set(Attributes::MISSING_HOST_HEADER, true);
        }

        // yawaf change: $server['SERVER_PORT'] can be set and empty when the server is listening on a unix socket
        if (isset($server['SERVER_PORT']) && $server['SERVER_PORT'] !== '') {
            if (!$haveHostHeader) {
                // yawaf change: fix issue #52 by casting to int
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
            // yawaf change: fix issue #66. On Apache, when requests are received with an absolute uri as target,
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
