<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr17;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use TanoWAF\WAFCore\Http\CookieParserInterface;
use TanoWAF\WAFCore\Http\HeaderParserInterface;
use TanoWAF\WAFCore\Http\QueryStringParserInterface;
use TanoWAF\WAFCore\ServerRequest\Psr7\Attributes;
use TanoWAF\WAFCore\ServerRequest\Psr7\ServerRequest;
use TanoWAF\WAFCore\ServerRequest\Psr7\ServerRequestConverterInterface;

/**
 * A ServerRequestFactory which
 * - can build the requests out of data in $_SERVER and also $_POST, $_FILES (though use of the latter 2 might change)
 * - can build the requests out of the data gotten from RoadRunner and Swoole
 * - implements ServerRequestFactoryInterface from PSR17
 * - tries to build a Request as faithful as possible to the received http payload, by eschewing usage of data
 *   commonly (and, sadly, unreliably) pre-parsed by php or the webserver, such as $_GET and $_COOKIE
 *
 * Original code taken from Nyholm\Psr7Server\ServerRequestCreator and patched to better work for the proxy use-case
 * and fix known bugs (and to stay as close as possible to the ServerRequestFactoryInterface API).
 * @see https://github.com/Nyholm/psr7-server/issues/65, https://github.com/Nyholm/psr7-server/issues/62, https://github.com/Nyholm/psr7-server/pull/49
 *
 * @todo add support for trusted proxies in front of us: allow whitelisting their IPs and the headers such as x-forwarded-...
 */
class ServerRequestFactory implements ServerRequestFactoryInterface, ServerRequestConverterInterface
{
    protected UriFactoryInterface $uriFactory;
    protected UploadedFileFactoryInterface $uploadedFileFactory;
    protected StreamFactoryInterface $streamFactory;

    protected CookieParserInterface $cookieParser;
    protected HeaderParserInterface $headerParser;
    protected QueryStringParserInterface $queryStringParser;

    public function __construct(UriFactoryInterface $uriFactory, UploadedFileFactoryInterface $uploadedFileFactory, StreamFactoryInterface $streamFactory,
        CookieParserInterface $cookieParser, HeaderParserInterface $headerParser, QueryStringParserInterface $queryStringParser)
    {
        $this->uriFactory = $uriFactory;
        $this->uploadedFileFactory = $uploadedFileFactory;
        $this->streamFactory = $streamFactory;

        $this->cookieParser = $cookieParser;
        $this->headerParser = $headerParser;
        $this->queryStringParser = $queryStringParser;
    }

    /**
     * @param array $serverParams should be consistent with $_SERVER
     * @param array|null $post $serverParams should be consistent with $_POST
     * @param array|null $files should be consistent with $_FILES
     */
    public function createServerRequest(string $method, $uri, array $serverParams = [], array|null $post = null, array|null $files = null): ServerRequest
    {
        // waf-core change: never call 'getallheaders' - as we allow callers to monkey-patch $_SERVER
        $headers = static::getHeadersFromServer($serverParams);

        $request = $this->fromArrays($method, $uri, $headers, \fopen('php://input', 'r') ?: null, $serverParams, $post, $files);

        return $request;
    }

    /**
     * Creates a ServerRequest out of the PHP superglobals $_SERVER, $_POST, $_FILES (but not $_GET, $_COOKIES)
     */
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

        $request = $this->createServerRequest($method, $uri, $server, $_POST, $_FILES);

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
     * "transforms" an instance of ServerRequestInterface into a ServerRequest.
     * @return ServerRequest (was: NB: if the $request passed in is a ServerRequest, the original instance itself is returned)
     */
    public function fromServerRequest(ServerRequestInterface $request): ServerRequest
    {
        if ($request instanceof ServerRequest) {
            $serverRequest = clone $request;
        } else {
            $serverRequest = new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders(),
                $request->getBody(), $request->getProtocolVersion(), $request->getServerParams());
            $serverRequest = $serverRequest
                ->withParsedBody($request->getParsedBody())
                ->withUploadedFiles($request->getUploadedFiles());
        }

        $serverRequest->setCookieParser($this->cookieParser)
            ->setHeaderParser($this->headerParser)
            ->setQueryStringParser($this->queryStringParser);

        return $serverRequest;
    }

    /**
     * Create a new uri from server variables.
     * NB: eschews access to $_GET.
     * NB: trusts the Host header over SERVER_PORT, SERVER_NAME
     *
     * @param array $server $_SERVER or an array having compatible structure and data
     */
    protected function createUriFromArray(array $server, Attributes $requestAttributes): UriInterface
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

    /**
     * NB: unlike the original class, this implementation will eschew usage of $_COOKIE, $_GET.
     * @todo... the signature this method is silly, as it gets plenty of redundant data, and one can not tell by looking
     *          at it which data is going to be considered truthful
     * @todo see the logic in Symfony\Component\HttpFoundation\Request::createFromGlobals for comparison
     * @throws \InvalidArgumentException
     */
    protected function fromArrays(string $method, $uri, array $headers = [], $body = null, array $server = [],
        array|null $post = null, array|null $files = null): ServerRequest
    {
        $requestAttributes = new Attributes();

        $parsedBody = null;
        if ('POST' === $method) {
            foreach ($headers as $headerName => $headerValue) {
                if (true === \is_int($headerName) || 'content-type' !== \strtolower($headerName)) {
                    continue;
                }
                if (\in_array(
                    \strtolower(\trim(\explode(';', $headerValue, 2)[0])),
                    ['application/x-www-form-urlencoded', 'multipart/form-data']
                )) {
                    $parsedBody = $post;

                    break;
                }
            }
            /// @todo consistency check: if $_POST is not empty, and 'content-type' header is not one of the expected 2, tag the response
        }

        if (false === isset($server['SERVER_PROTOCOL'])) {
            $protocol = '1.1';
            $requestAttributes->set(Attributes::SERVER_PROTOCOL_SYNTHETIC, true);
        } else {
            $protocol = \str_replace('HTTP/', '', $server['SERVER_PROTOCOL']);
        }

        // waf-core change: Psr17Factory::createServerRequest misses the ability of ServerRequest::__construct to work off
        // headers. That in turn requires more work immediately afterwards to patch in the headers, except for the
        // Host one. So we go straight for ServerRequest::__construct instead

/// @todo... reconcile differences between $_GET and $server['QUERY_STRING'], as well as between $_COOKIE and $headers['cookie'],
///          optionally saving them as attributes in the request.
///          Eg:
///          1. php converts ' ' and '.' in $_GET to _ => we should not do that
///          2. other languages/frameworks do allow arrays of values via ?a=one&a=two instead of a[]=one&a[]=two !!!
///          3. php does funny things when there are multiple spaces/tabs in cookie names
///          (see commented-out tests in BA_ServerRequestCreatorTest)
///          About point 1: see fe. https://github.com/symfony/symfony/blob/8.1/src/Symfony/Component/HttpFoundation/HeaderUtils.php#L201C62-L201C75
///          About point 2: see https://stackoverflow.com/questions/1746507/authoritative-position-of-duplicate-http-get-query-keys

        // waf-core change: avoid one useless clone call in handling $body

        $serverRequest = new ServerRequest($method, $uri, $headers, $body, $protocol, $server);

        $serverRequest->setCookieParser($this->cookieParser)
            ->setHeaderParser($this->headerParser)
            ->setQueryStringParser($this->queryStringParser);

        // waf-core change: avoid doing double-work with the Query Params, as they are first built by a call to `parse_str`
        // in the ServerRequest constructor, then immediately overwritten with the `->withQueryParams($get)` call.
        // Same for the Cookie Params
        //$serverRequest = $serverRequest
        //    ->withCookieParams($cookie)
        //    ->withQueryParams($get);
        if ($parsedBody !== null) {
            $serverRequest = $serverRequest->withParsedBody($parsedBody);
        }
        if ($files) {
            $serverRequest = $serverRequest->withUploadedFiles($this->normalizeFiles($files));
        }

        if (isset($server['REMOTE_ADDR'])) {
            $requestAttributes->set(Attributes::REMOTE_ADDR, $server['REMOTE_ADDR']);
        }
        if (isset($server['REMOTE_PORT'])) {
            $requestAttributes->set(Attributes::REMOTE_PORT, $server['REMOTE_PORT']);
        }

        // waf-core change: add an attribute bag to the request
        $serverRequest = $serverRequest->withAttribute(Attributes::class, $requestAttributes);

        return $serverRequest;
    }

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
     * Improved version of code from Nyholm\Psr7Server\ServerRequestCreator::getHeadersFromServer(), originally from
     * Laminas\Diactoros\marshalHeadersFromSapi().
     * @todo... test differences with https://github.com/ralouphie/getallheaders/blob/develop/src/getallheaders.php for hackish cases
     *          (see also the comments in https://www.php.net/manual/en/function.apache-request-headers.php)
     *          For a start, we should add an `ucwords` call to be compatible...
     *          Also, we should replace spaces in header names (in case someone edited $server by hand)
     */
    public static function getHeadersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            // Apache prefixes environment variables with REDIRECT_
            // if they are added by rewrite rules
            if (\str_starts_with($key, 'REDIRECT_')) {
                $key = \substr($key, 9);

                // We will not overwrite existing variables with the
                // prefixed versions, though
                if (\array_key_exists($key, $server)) {
                    continue;
                }
            }

            // wafcore change: `if ($value)` changed to `$value !== ''` (fix issue #67)

            if ($value !== '' && \str_starts_with($key, 'HTTP_')) {
                // wafcore change: make the generated headers use Snake-Case
                //$name = \strtr(\strtolower(\substr($key, 5)), '_', '-');
                $name = str_replace(' ', '-', \ucwords(\strtolower(\str_replace('_', ' ', \substr($key, 5)))));
                $headers[$name] = $value;

                continue;
            }

            /// @todo... limit this to CONTENT_TYPE, CONTENT_LENGTH, CONTENT_MD5?
            if ($value !== '' && \str_starts_with($key, 'CONTENT_')) {
                $name = 'Content-'.\ucfirst(\strtolower(\substr($key, 8)));
                $headers[$name] = $value;

                //continue;
            }
        }

        /// @todo do we have to uncomment this?
        /*if (!isset($headers['Authorization'])) {
            if (isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($server['PHP_AUTH_USER'])) {
                $basic_pass = isset($server['PHP_AUTH_PW']) ? $server['PHP_AUTH_PW'] : '';
                $headers['Authorization'] = 'Basic ' . base64_encode($server['PHP_AUTH_USER'] . ':' . $basic_pass);
            } elseif (isset($server['PHP_AUTH_DIGEST'])) {
                $headers['Authorization'] = $server['PHP_AUTH_DIGEST'];
            }
        }*/

        return $headers;
    }
}
