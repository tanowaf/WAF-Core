<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr17;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use TanoWAF\WAFCore\Http\CookieParserInterface;
use TanoWAF\WAFCore\Http\HeaderParserInterface;
use TanoWAF\WAFCore\Http\QueryStringParserInterface;
use TanoWAF\WAFCore\ServerRequest\Psr7\Attributes;
use TanoWAF\WAFCore\ServerRequest\Psr7\ServerRequest;
use TanoWAF\WAFCore\Stdlib;

/**
 * A ServerRequestFactory which
 * - builds the requests out of data in $_SERVER and also $_POST, $_FILES (though use of the latter 2 might change)
 * - tries to build a Request as faithful as possible to the received http payload, by eschewing usage of data
 *   commonly (and, sadly, unreliably) pre-parsed by php or the webserver, such as $_GET and $_COOKIE
 *
 * Original code taken from (parts of) Nyholm\Psr7Server\ServerRequestCreator and patched to better work for the proxy
 * use-case (and to stay as close as possible to the ServerRequestFactoryInterface API).
 */
class ServerRequestFactory implements ServerRequestFactoryInterface
{
    protected UploadedFileFactoryInterface $uploadedFileFactory;
    protected StreamFactoryInterface $streamFactory;

    protected CookieParserInterface $cookieParser;
    protected HeaderParserInterface $headerParser;
    protected QueryStringParserInterface $queryStringParser;

    public function __construct(UploadedFileFactoryInterface $uploadedFileFactory, StreamFactoryInterface $streamFactory,
        CookieParserInterface $cookieParser, HeaderParserInterface $headerParser, QueryStringParserInterface $queryStringParser)
    {
        $this->uploadedFileFactory = $uploadedFileFactory;
        $this->streamFactory = $streamFactory;

        $this->cookieParser = $cookieParser;
        $this->headerParser = $headerParser;
        $this->queryStringParser = $queryStringParser;
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequest
    {
        // waf-core change: never call 'getallheaders' - as we allow callers to monkey-patch $_SERVER
        $headers = Stdlib::getHeadersFromServer($serverParams);

        $post = null;
        if ('POST' === $method /*$serverParams['REQUEST_METHOD']*/) {
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

        $request = $this->fromArrays($method, $uri, $headers, \fopen('php://input', 'r') ?: null, $serverParams, $post, $_FILES);

        return $request;
    }

    /**
     * NB: unlike the original class, this implementation will in fact eschew usage of $_COOKIE, $_GET
     * @todo... the signature this method is silly, as it gets plenty of redundant data, and one can not tell by looking
     *          at it which data is going to be considered truthful
     * @todo see the logic in Symfony\Component\HttpFoundation\Request::createFromGlobals for comparison
     */
    protected function fromArrays(string $method, $uri, array $headers = [], $body = null, array $server = [],
        array|null $post = null, array $files = []): ServerRequest
    {
        $requestAttributes = new Attributes();

        if (false === isset($server['SERVER_PROTOCOL'])) {
            $protocol = '1.1';
            $requestAttributes->set(Attributes::SERVER_PROTOCOL_SYNTHETIC, true);
        } else {
            $protocol =\str_replace('HTTP/', '', $server['SERVER_PROTOCOL']);
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

        $serverRequest = new ServerRequest($method, $uri, $headers, null, $protocol, $server);

        $serverRequest->setCookieParser($this->cookieParser)
            ->setHeaderParser($this->headerParser)
            ->setQueryStringParser($this->queryStringParser);

        // waf-core change: avoid doing double-work with the Query Params, as they are first built by a call to `parse_str`
        // in the ServerRequest constructor, then immediately overwritten with the `->withQueryParams($get)` call.
        // Same for the Cookie Params
        $serverRequest = $serverRequest
            //->withCookieParams($cookie)
            //->withQueryParams($get)
            ->withParsedBody($post)
            ->withUploadedFiles($this->normalizeFiles($files));

        if (isset($server['REMOTE_ADDR'])) {
            $requestAttributes->set(Attributes::REMOTE_ADDR, $server['REMOTE_ADDR']);
        }
        if (isset($server['REMOTE_PORT'])) {
            $requestAttributes->set(Attributes::REMOTE_PORT, $server['REMOTE_PORT']);
        }

        // waf-core change: add an attribute bag to the request
        $serverRequest = $serverRequest->withAttribute(Attributes::class, $requestAttributes);

        if (null === $body) {
            return $serverRequest;
        }

        if (\is_resource($body)) {
            $body = $this->streamFactory->createStreamFromResource($body);
        } elseif (\is_string($body)) {
            $body = $this->streamFactory->createStream($body);
        } elseif (!$body instanceof StreamInterface) {
            throw new \InvalidArgumentException('The $body parameter to ServerRequestFactory::fromArrays must be string, resource or StreamInterface');
        }

        return $serverRequest->withBody($body);
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
}
