<?php

namespace TanoWAF\WAFCore\Response\Psr7;

use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Http\HeaderParsingCapableInterface;

interface ResponseConverterInterface
{
    public function fromResponse(ResponseInterface $response): HeaderParsingCapableInterface;
}
