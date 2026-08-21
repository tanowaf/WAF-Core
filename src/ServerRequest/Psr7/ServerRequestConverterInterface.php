<?php

namespace TanoWAF\WAFCore\ServerRequest\Psr7;

use Psr\Http\Message\ServerRequestInterface;

interface ServerRequestConverterInterface
{
    public function fromServerRequest(ServerRequestInterface $request): HeaderParsingCapableServerRequestInterface;
}
