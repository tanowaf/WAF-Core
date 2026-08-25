<?php

namespace TanoWAF\WAFCore\Swoole;

use Psr\Http\Message\ResponseInterface;

class Emitter
{
    public function emit(ResponseInterface $response, \OpenSwoole\Http\Response|\Swoole\Http\Response $swooleResponse): void
    {
        $swooleResponse->status($response->getStatusCode());

/// @todo... handle correctly the known cases of headers requiring different glueing
        foreach ($response->getHeaders() as $key => $headerArray) {
            $swooleResponse->header($key, implode('; ', $headerArray));
        }

/// @todo... are there specific cases we should take into account? see https://github.com/imefisto/psr-swoole-native/blob/master/src/ResponseMerger.php
        $body = $response->getBody();
        $size = $body->getSize();
        if ($size !== 0) {
            if ($response->getBody()->isSeekable()) {
                $response->getBody()->rewind();
            }
            $body = $body->getContents();
            if ($body !== '') {
                $swooleResponse->write($body);
            }
        }

        $swooleResponse->end();
    }
}
