<?php

namespace TanoWAF\WAFCore\Response\Psr7;

use Psr\Http\Message\ResponseInterface;

interface ResponseConverterInterface
{
    public function fromResponse(ResponseInterface $response): InspectableResponseInterface;
}
