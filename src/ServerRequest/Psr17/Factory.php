<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr17;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

class Factory implements ExtendedFactoryInterface
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $uri, [], null, '1.1', $serverParams);
    }

    public function createServerRequestEx(string $method, $uri, array $serverParams = [], array $headers = [], string $protocolVersion = '1.1'): ServerRequestInterface
    {
        return new ServerRequest($method, $uri, $headers, null, $protocolVersion, $serverParams);
    }
}
