<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests that the Proxy is in basic working condition - without running any rule.
 */
class AB_ProxySmokeTest extends ProxyTestCase
{
    /**
     * Tests direct access to the proxy, without the test id cookie: an HTTP 400 response is expected
     */
    #[DataProvider('proxyTestsDataProvider')]
    public function testProxyAsUpstreamNoTestCookie(string|null $clientType = null, string $proxyScheme = 'http'): void
    {
        $clientOptions = ['base_uri' => static::getProxyBaseUri()];
        if ($proxyScheme === 'unix') {
            $clientOptions['bindto'] = $_ENV['WAF_SOCKET'];
        }
        $client = $this->getClient($clientOptions, ['client_type' => $clientType]);
        $response = $client->request('GET', static::getProxyPath());
        // Note that in case of php errors, the status code will be 200 when display_errors in php.ini is on, and 500 when it is off
        $this->assertResponseHasStatusCode(400, $response, $response->getContent(false));
        $this->assertSame('This url can only be accessed by the test suite', $response->getContent(false));
    }

    /**
     * Tests direct access to the proxy, with the test id cookie: an HTTP 403 response is expected
     */
    #[DataProvider('proxyTestsDataProvider')]
    public function testProxyAsUpstreamWithTestCookie(string|null $clientType = null, string $proxyScheme = 'http'): void
    {
        $clientOptions = ['base_uri' => static::getProxyBaseUri()];
        if ($proxyScheme === 'unix') {
            $clientOptions['bindto'] = $_ENV['WAF_SOCKET'];
        }
        // NB: we do _not_ want to use $this->getTestClient here
        $client = ServerTestCase::getTestClient($clientOptions, ['client_type' => $clientType]);
        $response = $client->request('GET', static::getProxyPath());
        $this->assertResponseIsProxyDenial($response, $response->getContent(false));
    }

    /**
     * Tests access to the upstream server via the proxy, with the test id cookie but no access rules defined: an HTTP 403 response is expected
     * @todo this test could also have an $upstreamClientType arg
     */
    #[DataProvider('proxyTestsDataProvider')]
    public function testProxyAsProxyWithoutRules(string|null $clientType = null, string $proxyScheme = 'http'): void
    {
        $response = $this->request([], 'GET', '', ['client_type' => $clientType, 'proxy_scheme' => $proxyScheme]);
        // Without any config, the firewall should return a DENY response
        $this->assertResponseIsProxyDenial($response, $response->getContent(false));
    }

    public static function proxyTestsDataProvider(): array
    {
        $out = [];
        foreach (self::getSupportedProxySchemes() as $proxyScheme) {
            foreach (self::getSupportedClientTypes() as $clientType) {
                if ($proxyScheme === 'unix' && $clientType === 'native') {
                    continue;
                }
                $out[] = [$clientType, $proxyScheme];
            }
        }
        return $out;
    }
}
