<?php

namespace TanoWAF\WAFCore\Swoole;

use Psr\Http\Message\ResponseInterface;

class Emitter
{
    /**
     * @phpstan-ignore class.notFound
     * @noinspection PhpUndefinedClassInspection
     */
    public function emit(ResponseInterface $response, \OpenSwoole\Http\Response|\Swoole\Http\Response $swooleResponse): void
    {
        $swooleResponse->status($response->getStatusCode());

        if ($swooleResponse instanceof \Swoole\Http\Response) {
            foreach ($response->getHeaders() as $key => $headerArray) {
                $swooleResponse->header($key, $headerArray);
            }
        } else {
/// @todo test if openswoole accepts array values besides strings too. In case, do not glue stuff
/// @todo... handle correctly the known cases of headers requiring different glueing
            foreach ($response->getHeaders() as $key => $headerArray) {
                $swooleResponse->header($key, implode('; ', $headerArray));
            }
        }

/// @todo... are there specific cases we should take into account? see https://github.com/imefisto/psr-swoole-native/blob/master/src/ResponseMerger.php

        $body = $response->getBody();
        $size = $body->getSize();
        if ($size === 0) {
            $body = '';
        } else {
            if ($response->getBody()->isSeekable()) {
                $response->getBody()->rewind();
            }
            $body = $body->getContents();
        }

        // note: according to the docs, using separate write() calls disables support for resp. compression and triggers
        // chunked encoding...
        $swooleResponse->end($body);
    }
}
