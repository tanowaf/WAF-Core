<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Swoole;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\ServerRequest\Psr17\ServerRequestFactory as BaseServerRequestFactory;
use TanoWAF\WAFCore\ServerRequest\Psr7\ServerRequest;

class ServerRequestFactory extends BaseServerRequestFactory
{
    /**
     * @param \OpenSwoole\Core\Psr\ServerRequest $request
     */
    public function fromOpenSwooleServerRequest(ServerRequestInterface $request): ServerRequest
    {
        $serverRequest = $this->fromServerRequest($request);

        // fix the request Uri - see https://github.com/openswoole/ext-openswoole/issues/403
        $params = $request->getServerParams();
        $uri = $params['request_uri'];
        if (@$params['query_string'] !== '') {
/// @todo... check if there is any urlencoding at play here
            $uri .= '?' . $params['query_string'];
        }

        $serverRequest = $serverRequest->withUri($this->uriFactory->createUri($uri));

        return $serverRequest;
    }

    public function fromSwooleRequest(\OpenSwoole\Http\Request|\Swoole\Http\Request $request): ServerRequest
    {
        $headers = $request->header;
/// @todo... verify the format of $headers (esp. double headers, continuation, 2-lines 'Cookie')

        $body = $request->getContent();
        if ($body === false) {
            $body = null;
        }

/// @todo...
        $uri = '...';

        $server = $request->server;
/// @todo... transform  $server into what we expect in fromArrays, ie. the $_SERVER format

        $serverRequest = $this->fromArrays($request->getMethod(), $uri, $headers, $body, $server, $request->post, $request->files);

        return $serverRequest;
    }
}
