<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Filter\Response;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Exception\RequestDenied;

interface ResponseFilterInterface
{
    /**
     * NB:
     * @throws RequestDenied when the response is black-holed and does not have to be returned further back
     */
    public function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface;
}
