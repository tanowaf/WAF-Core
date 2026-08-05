<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr17;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

interface ExtendedFactoryInterface extends ServerRequestFactoryInterface
{
    public function createServerRequestEx(string $method, $uri, array $serverParams = [], array $headers = [], string $protocolVersion = '1.1'): ServerRequestInterface;
}
