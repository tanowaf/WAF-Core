<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Proxy;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

interface ProxyInterface extends RequestHandlerInterface
{
    /**
     * @return string @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Via
     */
    public function getViaHeader(ServerRequestInterface $request): string;
}
