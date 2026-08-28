<?php

namespace TanoWAF\WAFCore\ServerRequest\Psr7;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Http\HeaderParsingCapableInterface;

interface ServerRequestConverterInterface
{
    public function fromServerRequest(ServerRequestInterface $request): HeaderParsingCapableServerRequestInterface;
}
