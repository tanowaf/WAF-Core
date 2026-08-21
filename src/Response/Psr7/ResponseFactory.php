<?php

namespace TanoWAF\WAFCore\Response\Psr7;

use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Http\CookieParserInterface;
use TanoWAF\WAFCore\Http\HeaderParserInterface;

class ResponseFactory implements ResponseConverterInterface
{
    protected CookieParserInterface $cookieParser;
    protected HeaderParserInterface $headerParser;

    public function __construct(CookieParserInterface $cookieParser, HeaderParserInterface $headerParser)
    {
        $this->cookieParser = $cookieParser;
        $this->headerParser = $headerParser;
    }

    public function fromResponse(ResponseInterface $response): Response
    {
        if ($response instanceof Response) {
            $newResponse = clone $response;
        } else {
            $newResponse = new Response($response->getStatusCode(), $response->getHeaders(), $response->getBody(), $response->getProtocolVersion(), $response->getReasonPhrase());
        }
        $newResponse->setCookieParser($this->cookieParser);
        $newResponse->setHeaderParser($this->headerParser);
        return $newResponse;
    }
}
