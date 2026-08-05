<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use TanoWAF\WAFCore\Proxy\Proxy;

/// @todo declare dependency on SmokeTest
class CB_HTTPErrorsTest extends ProxyTestCase
{
    /**
     * Test getting back a 504 error if upstream is slow in sending back responses
     */
    #[DataProvider('getCommonDataProviderOptions')]
    public function testSlowUpstream(string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        if ($upstreamClientType === 'guzzle_stream') {
            $this->markTestIncomplete('Test known to fail atm with Guzzle/Stream client. See issue #3809...');
        }

        $rule = [['always' => true]];
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config' => json_encode($rule), 'X-YAWAF-Force-Accept-Encoding' => 'identity']],
            'GET',
            static::getServerPath() . '?action=slowloris&action_args[]=5',
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(Proxy::UPSTREAM_TIMEOUT_STATUS_CODE, $response, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(Proxy::UPSTREAM_TIMEOUT_STATUS_CODE, null, 'Exception thrown by client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    /**
     * Test getting back a 404 error if using a bad uri path for upstream
     */
    #[DataProvider('getCommonDataProviderOptions')]
    public function test404Upstream(string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        $rule = [['always' => true]];
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config' => json_encode($rule), 'X-YAWAF-Force-Accept-Encoding' => 'identity']],
            'GET',
            '/no_such_page',
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(404, $response, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(404, null, 'Exception thrown by client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    /**
     * Test getting back a 502 error if using a bad port for upstream
     */
    #[DataProvider('getCommonDataProviderOptions')]
    public function testNoUpstream(string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        if ($serverScheme === 'unix') {
            $this->markTestIncomplete('Todo: allow the proxy to connect to an overridden (but controlled) unix socket path');
        }

        $rule = [['always' => true]];
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config' => json_encode($rule), 'X-YAWAF-Force-Accept-Encoding' => 'identity', 'X-YAWAF-Upstream-Port-Override' => intval(@$_ENV['HTTPSERVER_PORT']) + 3000]],
            'GET',
            static::buildUrl([
                    'scheme' => 'http', 'host' => $_ENV['HTTPSERVER_HOST'], 'port' => intval(@$_ENV['HTTPSERVER_PORT']) + 3000
                ]) . static::getServerPath(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(Proxy::UPSTREAM_ERROR_STATUS_CODE, $response, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(Proxy::UPSTREAM_ERROR_STATUS_CODE, null, 'Exception thrown by client while communicating to the proxy: ' . $e->getMessage());
        }
    }
}
