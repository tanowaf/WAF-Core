<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr7;

use Nyholm\Psr7\MessageTrait;
use Nyholm\Psr7\RequestTrait;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use TanoWAF\WAFCore\Http\CookieParserAwareTrait;
use TanoWAF\WAFCore\Http\HeaderParserAwareTrait;
use TanoWAF\WAFCore\Http\HeaderParsingCapableInterface;
use TanoWAF\WAFCore\Http\QueryStringParserAwareTrait;

/**
 * A reimplementation of Nyholm\Psr7\ServerRequest, which sadly has private members and is thus not easy to extend.
 * See inline comments for the changes.
 *
 * @todo... make withCookieParams always throw (the ServerRequestInterface docs state that these methods
 *          are forbidden from modifying the underlying headers and uri...)
 * @todo... verify if we should reimplement withRequestTarget so that it always throws, as that would allow having a
 *          request-target that is not in sync with $this->uri
 */
class ServerRequest implements ServerRequestInterface, HeaderParsingCapableInterface
{
    use MessageTrait;
    use RequestTrait;

    use CookieParserAwareTrait;
    use HeaderParserAwareTrait;
    use QueryStringParserAwareTrait;

    /** @var array */
    private array $attributes = [];

    /** @var string[]|null */
    private array|null $cookieParams;
    /** @var string[]|null */
    private array|null $cookieHeader;

    /** @var array|object|null */
    private $parsedBody;

    /** @var string[]|null */
    private array|null $queryParams;
    private string|null $queryString;

    /** @var array */
    private array $serverParams;

    /** @var UploadedFileInterface[] */
    private array $uploadedFiles = [];

    /**
     * @param string $method HTTP method
     * @param string|UriInterface $uri URI
     * @param array $headers Request headers
     * @param string|resource|StreamInterface|null $body Request body
     * @param string $version Protocol version
     * @param array $serverParams Typically the $_SERVER superglobal
     */
    public function __construct(string $method, $uri, array $headers = [], $body = null, string $version = '1.1', array $serverParams = [])
    {
        $this->serverParams = $serverParams;

        if (!($uri instanceof UriInterface)) {
            $uri = new Uri($uri);
        }

        $this->method = $method;
        $this->uri = $uri;
        $this->setHeaders($headers);
        $this->protocol = $version;

        // waf-core change: use a more flexible query string params parser, but avoid parsing the QS into params until requested
        //\parse_str($uri->getQuery(), $this->queryParams);
        $this->updateQueryStringFromUri();

        if (!$this->hasHeader('Host')) {
            $this->updateHostFromUri();
        }

        // waf-core change: work out the cookies on our own, so there is no need to inject them later from $_COOKIE
        $this->updateCookieFromHeaders();

        // If we got no body, defer initialization of the stream until ServerRequest::getBody()
        if ('' !== $body && null !== $body) {
            $this->stream = Stream::create($body);
        }
    }

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @return static
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;

        return $new;
    }

    /**
     * NB: this does _not_ produce the same values as found in $_COOKIE - see details in CookieParser::parseCookies.
     * Notably, the values of the returned array can be arrays!
     */
    public function getCookieParams(): array
    {
        if ($this->cookieParams === null) {
            if (is_array($this->cookieHeader) && $this->cookieHeader) {
                // we allow multiple Cookie headers, as per the http2 spec. Note that those are not valid in http 1.1,
                // so we handle that differently in that case.
                /// @todo Check how php handles that by default
                /// @todo see also the discussion at https://github.com/httpwg/http-extensions/issues/2541 and https://github.com/cloudflare/pingora/issues/892
                ///       (we should concatenate the multiple cookies headers into one when forwarding the request upstream...)
                if (count($this->cookieHeader) > 1) {
                    if ((float)$this->protocol >= 2) {
                        $this->cookieParams = $this->cookieParser->parseCookies(implode('; ', $this->cookieHeader), $errorsFound);
                    } else {
/// @todo... throw / log an error
                        $this->cookieParams = [];
                    }
                } else {
                    $this->cookieParams = $this->cookieParser->parseCookies($this->cookieHeader[0], $errorsFound);
                }
            } else {
                $this->cookieParams = [];
            }
        }

        return $this->cookieParams;
    }

    /**
     * @return static
     *
     * @todo... always throw
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $new = clone $this;
        $new->cookieParams = $cookies;

        return $new;
    }

    public function getQueryParams(): array
    {
        if ($this->queryParams === null) {
            if ($this->queryString === null || $this->queryString === '') {
                $this->queryParams = [];
            } else {
                $this->queryParams = $this->queryStringParser->parseQueryString($this->queryString);
            }
        }
        return $this->queryParams;
    }

    /**
     * The docs for ServerRequestInterface clearly state that this method can not modify the headers, so we just throw,
     * in order to maintain consistency of this instance's data
     *
     * @return static
     * @throws \Exception
     * @todo find a better exception
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        throw new \Exception('Method withQueryParams is not supported');

        //$new = clone $this;
        //$new->queryParams = $query;
        //return $new;
    }

    /**
     * @return array|object|null
     */
    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    /**
     * @return static
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        if (!\is_array($data) && !\is_object($data) && null !== $data) {
            throw new \InvalidArgumentException('First parameter to withParsedBody MUST be object, array or null');
        }

        $new = clone $this;
        $new->parsedBody = $data;

        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return mixed
     */
    public function getAttribute($attribute, $default = null)
    {
        if (!\is_string($attribute)) {
            throw new \InvalidArgumentException('Attribute name must be a string');
        }

        if (false === \array_key_exists($attribute, $this->attributes)) {
            return $default;
        }

        return $this->attributes[$attribute];
    }

    /**
     * @return static
     */
    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $new = clone $this;
        $new->attributes[$name] = $value;

        return $new;
    }

    /**
     * @return static
     */
    public function withoutAttribute(string $name): ServerRequestInterface
    {
        if (false === \array_key_exists($name, $this->attributes)) {
            return $this;
        }

        $new = clone $this;
        unset($new->attributes[$name]);

        return $new;
    }

    /**
     * @return static
     */
    public function withUri(UriInterface $uri, $preserveHost = false, $preserveQueryStingParams = false): RequestInterface
    {
        if ($uri === $this->uri) {
            return $this;
        }

        $new = clone $this;
        $new->uri = $uri;

        if (!$preserveHost || !$this->hasHeader('Host')) {
            $new->updateHostFromUri();
        }

        // waf-core change
        if (!$preserveQueryStingParams) {
            $new->updateQueryStringFromUri();
        }

        return $new;
    }

    /**
     * @return static
     */
    public function withHeader(string $name, $value, $preserveCookieParams = false): MessageInterface
    {
        $value = $this->validateAndTrimHeader($name, $value);
        $normalized = \strtr($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

        $new = clone $this;
        if (isset($new->headerNames[$normalized])) {
            unset($new->headers[$new->headerNames[$normalized]]);
        }
        $new->headerNames[$normalized] = $name;
        $new->headers[$name] = $value;

        // waf-core change
        if (!$preserveCookieParams && $normalized === 'cookie') {
            $new->updateCookieFromHeaders();
        }

        return $new;
    }

    /**
     * @return static
     */
    public function withAddedHeader(string $name, $value, $preserveCookieParams = false): MessageInterface
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('Header name must be an RFC 7230 compatible string');
        }

        $new = clone $this;
        // waf-core change: add extra arg
        $new->setHeaders([$name => $value], $preserveCookieParams);

        return $new;
    }

    /**
     * @return static
     */
    public function withoutHeader(string $name, $preserveCookieParams = false): MessageInterface
    {
        $normalized = \strtr($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        if (!isset($this->headerNames[$normalized])) {
            /// @todo we do not call updateCookieFromHeaders to preserve immutability. But are there any chances that
            //        somehow $this->cookieHeader and $this->cookieParams had managed to be set?
            return $this;
        }

        $header = $this->headerNames[$normalized];
        $new = clone $this;
        unset($new->headers[$header], $new->headerNames[$normalized]);

        // waf-core change
        if (!$preserveCookieParams && $normalized === 'cookie') {
            $new->updateCookieFromHeaders();
        }

        return $new;
    }

    private function setHeaders(array $headers, $preserveCookieParams = false): void
    {
        foreach ($headers as $header => $value) {
            if (\is_int($header)) {
                // If a header name was set to a numeric string, PHP will cast the key to an int.
                // We must cast it back to a string in order to comply with validation.
                $header = (string) $header;
            }
            $value = $this->validateAndTrimHeader($header, $value);
            $normalized = \strtr($header, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
            if (isset($this->headerNames[$normalized])) {
                $header = $this->headerNames[$normalized];
                $this->headers[$header] = \array_merge($this->headers[$header], $value);
            } else {
                $this->headerNames[$normalized] = $header;
                $this->headers[$header] = $value;
            }

            // waf-core change
            if (!$preserveCookieParams && $normalized === 'cookie') {
                $this->updateCookieFromHeaders();
            }
        }
    }

    protected function updateQueryStringFromUri(): void
    {
        $this->queryString = $this->uri->getQuery();
        $this->queryParams = null;
    }

    protected function updateCookieFromHeaders(): void
    {
        if ($this->hasHeader('cookie')) {
            $this->cookieHeader = $this->getHeader('cookie');
            $this->cookieParams = null;
        } else {
            $this->cookieHeader = null;
            $this->cookieParams = [];
        }
    }

    public function validateHeaderValue(string $headerName): bool
    {
/// @todo... add a caching layer
        return $this->headerParser->validateHeaderValue($headerName, $this->getHeader($headerName), $errorsFound);
    }

    /**
     * @return string[]
     */
    public function normalizedHeaderValue(string $headerName, array|null &$errorsFound = []): array
    {
/// @todo... add a caching layer
        return $this->headerParser->normalizeHeaderValue($headerName, $this->getHeader($headerName), $errorsFound);
    }
}
