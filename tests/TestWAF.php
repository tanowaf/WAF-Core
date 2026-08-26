<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Proxy\ProxyInterface;
use TanoWAF\WAFCore\Server\MiddlewareAware;
use TanoWAF\WAFCore\UpstreamClient\GuzzleAdapter;
use TanoWAF\WAFCore\UpstreamClient\SymfonyHttpClientAdapter;
use TanoWAF\WAFCore\UpstreamClient\UpstreamClientFactory;
use TanoWAF\WAFCore\UpstreamClient\UpstreamClientInterface;

class TestWAF extends MiddlewareAware
{
    /// @todo instead of hardcoding these, we should get their value from the same env vars which are used to drive the
    ///       client-side of the tests
    const DEFAULT_UPSTREAMS = [
        'http' => 'http://127.0.0.1/server.php',
        'tcp' => 'tcp://127.0.0.1:80',
        'unix' => 'unix:/run/nginx.server.sock',

    ];
    const ACCESS_DENIED_STATUS_CODE = 403;
    const ACCESS_DENIED_RESPONSE = ['result' => 'Access denied'];
    const ERROR_STATUS_CODE = 500;
    const ERROR_RESPONSE = ['result' => 'Error'];

    protected function deniedResponse(ServerRequestInterface $request, \Throwable|null $e = null): ResponseInterface
    {
        $response = new Response(
            self::ACCESS_DENIED_STATUS_CODE,
            ['content-type' => 'application/json'],
            json_encode(self::ACCESS_DENIED_RESPONSE));
        if ($this->upstreamConnector instanceof ProxyInterface) {
            $response = $response->withAddedHeader('Via', $this->upstreamConnector->getViaHeader($request));
        }
        return $response;
    }

    protected function errorResponse(ServerRequestInterface|null $request = null, \Throwable|null $e = null): ResponseInterface
    {
        $response = self::getErrorResponse($e);
        if ($this->upstreamConnector instanceof ProxyInterface) {
            $response = $response->withAddedHeader('Via', $this->upstreamConnector->getViaHeader($request));
        }
        return $response;
    }

    public static function getErrorResponse(\Throwable|null $e = null): ResponseInterface
    {
        return new Response(
            self::ERROR_STATUS_CODE,
            ['content-type' => 'application/json'],
            // NB: never do this in production servers! You would be giving to callers information valuable for hacking your server.
            json_encode(self::ERROR_RESPONSE + ($e !== null ? ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()] : []))
        );
    }

    /**
     * @throws \Exception
     */
    public static function getUpstreamUri(string $scheme = 'http', int|null $portOverride = null): string
    {
        switch ($scheme) {
            case 'http':
                if (trim(@$_ENV['HTTPSERVER_HOST']) === '') {
                    throw new \Exception("Unsupported scheme for upstream server: $scheme");
                }
                if ($portOverride !== null && $portOverride !== 0) {
                    $port = ':' . $portOverride;
                } else {
                    $port = trim(@$_ENV['HTTPSERVER_PORT']) === '' ? '' : ':' . $_ENV['HTTPSERVER_PORT'];
                }
                return 'http://' . $_ENV['HTTPSERVER_HOST'] . $port . $_ENV['HTTPSERVER_PATH'];
            /// @todo...
            //case 'https':
            //case 'tcp':
            case 'unix':
                if ($portOverride !== null && $portOverride !== 0) {
                    throw new \Exception("Unsupported port override for upstream server: $scheme");
                }
                if (trim(@$_ENV['HTTPSERVER_SOCKET']) === '') {
                    throw new \Exception("Unsupported scheme for upstream server: $scheme");
                }
                return 'unix:' . $_ENV['HTTPSERVER_SOCKET'];
            default:
                throw new \InvalidArgumentException("Unsupported scheme for upstream server: $scheme");
        }
    }

    /**
     * @throws \Exception
     */
    public static function createUpstreamClient(string $clientType = '', array $options = []): UpstreamClientInterface
    {
        switch ($clientType) {
            case '':
                return (new UpstreamClientFactory())->createClient([
                    /// @todo enable this if SFHC is version 8.1 or later - can we figure it out?
                    //UpstreamClientInterface::OPT_CONNECT_TIMEOUT => 1.0,
                    UpstreamClientInterface::OPT_TIMEOUT => 2.0,
                ] + $options);
            case 'guzzle':
            case 'guzzle_curl':
                return new GuzzleAdapter([
                    /// @todo if the curl version is too old, guzzle will switch to using the stream handler.
                    ///       Uncomment this after having checked the default options used in creating the guzzle curl handler
                    //UpstreamClientInterface::OPT_TRANSPORT => 'curl',
                    UpstreamClientInterface::OPT_CONNECT_TIMEOUT => 1.0,
                    UpstreamClientInterface::OPT_TIMEOUT => 2.0,
                ] + $options);
            case 'guzzle_stream':
                return new GuzzleAdapter([
                    UpstreamClientInterface::OPT_TRANSPORT => 'native',
                    UpstreamClientInterface::OPT_CONNECT_TIMEOUT => 1.0,
                    UpstreamClientInterface::OPT_TIMEOUT => 2.0,
                ] + $options);
            case 'sfhc_native':
                return new SymfonyHttpClientAdapter([
                    UpstreamClientInterface::OPT_TRANSPORT => 'native',
                    /// @todo enable this if SFHC is version 8.1 or later - can we figure it out?
                    //UpstreamClientInterface::OPT_CONNECT_TIMEOUT => 1.0,
                    UpstreamClientInterface::OPT_TIMEOUT => 2.0,
                ] + $options);
            case 'sfhc_curl':
                return new SymfonyHttpClientAdapter([
                    UpstreamClientInterface::OPT_TRANSPORT => 'curl',
                    /// @todo enable this if SFHC is version 8.1 or later - can we figure it out?
                    //UpstreamClientInterface::OPT_CONNECT_TIMEOUT => 1.0,
                    UpstreamClientInterface::OPT_TIMEOUT => 2.0,
                ] + $options);
            default:
                throw new \InvalidArgumentException("Unsupported upstream client type: '$clientType'");
        }
    }
}
