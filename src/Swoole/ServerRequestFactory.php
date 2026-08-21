<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Swoole;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\ServerRequest\Psr17\ServerRequestFactory as BaseServerRequestFactory;
use TanoWAF\WAFCore\ServerRequest\Psr7\Attributes;
use TanoWAF\WAFCore\ServerRequest\Psr7\ServerRequest;

class ServerRequestFactory extends BaseServerRequestFactory
{
    /// @see https://github.com/imefisto/psr-swoole-native/
    public function fromSwooleRequest(\OpenSwoole\Http\Request|\Swoole\Http\Request $request): ServerRequest
    {
/// @todo... check that uppercased $server is what we expect in createUriFromArray and fromArrays, ie. the $_SERVER format
        $server = [];
        foreach ($request->server as $k => $v) {
            $server[strtoupper($k)] = $v;
        };

        $requestAttributes = new Attributes();

        $method = $request->getMethod();

/// @todo... check that $server is compatible with what we expect
        $uri = $this->createUriFromArray($server, $requestAttributes);

/// @todo... verify the format of $headers (esp. double headers, continuation, 2-lines 'Cookie')
        $headers = $request->header; // no need, atm this is already done by called code: array_map(fn($value) => is_array($value) ? $value : [$value], $request->header)

        /// @todo use instead a Stream?
        $body = $request->getContent();
        if ($body === false) {
            $body = null;
        }

        $serverRequest = $this->fromArrays($method, $uri, $headers, $body, $server, $request->post, $request->files);

        /** @var Attributes $ra */
        if (($ra = $serverRequest->getAttribute(Attributes::class)) === null) {
            $serverRequest = $serverRequest->withAttribute(Attributes::class, $requestAttributes);
        } else {
            foreach ($requestAttributes->keys() as $key) {
                $ra->set($key, $requestAttributes->get($key));
            }
        }

        return $serverRequest;
    }

    /**
     * @param \OpenSwoole\Core\Psr\ServerRequest $request
     */
    public function fromOpenSwooleServerRequest(ServerRequestInterface $request): ServerRequest
    {
        $serverRequest = $this->fromServerRequest($request);

        // fix the request Uri - see https://github.com/openswoole/ext-openswoole/issues/403
        $params = $request->getServerParams();
        $uri = $params['request_uri'];
        if (array_key_exists('query_string', $params) && $params['query_string'] !== '') {
/// @todo... check if there is any urlencoding at play here
            $uri .= '?' . $params['query_string'];
        }

        $serverRequest = $serverRequest->withUri($this->uriFactory->createUri($uri));

        return $serverRequest;
    }
}
