<?php

namespace TanoWAF\WAFCore\Tests;

use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\UpstreamClient\UpstreamClientInterface;

/**
 * A mock class used in load testing: return a response as quickly as possible, without even interrogating an upstream server
 */
class MockUpstreamClient implements UpstreamClientInterface
{
    public function withOptions(array $options): UpstreamClientInterface
    {
        throw new \Exception("withOptions is not implemented by the MockUpstreamClient");
    }

    public function getUserAgent(): string
    {
        return "MockClient";
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200, ['content-type' => 'application/json'], '{"result": "OK"}');
    }
}
