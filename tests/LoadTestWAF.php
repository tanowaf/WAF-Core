<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Server\MiddlewareAware;

class LoadTestWAF extends MiddlewareAware
{
    protected function deniedResponse(ServerRequestInterface $request, \Throwable|null $e = null): ResponseInterface
    {
        return new Response(
            403,
            ['content-type' => 'application/json'],
            '{"result": "Access denied"}'
        );
    }

    protected function errorResponse(ServerRequestInterface|null $request = null, \Throwable|null $e = null): ResponseInterface
    {
        return new Response(
            500,
            ['content-type' => 'application/json'],
            '{"result": "Error"}'
        );
    }

    public static function getErrorResponse(): ResponseInterface
    {
        return new Response(
            500,
            ['content-type' => 'application/json'],
            '{"result": "Error"}'
        );
    }
}
