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

        $uri = $this->createUriFromArray($server, $requestAttributes);

        $headers = static::getHeadersFromSwooleRequest($request);

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

    public static function getHeadersFromSwooleRequest(\OpenSwoole\Http\Request|\Swoole\Http\Request $request): array
    {
        $headers = [];
        foreach ($request->header as $k => $v) {
            // all servers but Swoole do drop these headers...
            if ($v === '') {
                continue;
            }
            // try to keep as close as possible to Stdlib::getHeadersFromServer
            $headers[\ucwords((string)$k, "-_")] = $v;
            // no need, atm this is already done by called code
            //array_map(fn($value) => is_array($value) ? $value : [$value], $request->header)
        };

        // retrieve the cookie header, since swoole removes it from $request->header
        if ($request->cookie && !array_key_exists('Cookie', $headers)) {

            // sadly this does not give us access to the original cookie header line(s)
            /*$cookies = [];
            foreach ($request->cookie as $k => $v) {
                $cookies[] = "$k=$v";
            }
            $headers['Cookie'] = implode('; ', $cookies);*/

            /// @todo... speed-up and harden this header parser
            ///          - avoid doing data copies with substr
            ///          - set a limit on header line length? (swoole does that on its own, eg. 100K chars headers are rejected with a 40x)
            ///          take a look at eg. the QBix server header parser
            $payload = $request->getData();
            $pos = strpos($payload, "\r\n\r\n");
            if ($pos !== false) {
                $headers['Cookie'] = [];
                foreach (explode("\r\n", substr($payload, 0, $pos)) as $headerLine) {
                    if (preg_match('/^Cookie:/i', $headerLine, $matches)) {
                        $headers['Cookie'][] = trim(substr($headerLine, 7), " \t");
                    }
                }
                $headers['Cookie'] = implode('; ', $headers['Cookie']);
            }
        }

        return $headers;
    }
}
