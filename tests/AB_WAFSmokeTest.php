<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests that the WAF is in basic working condition - without running any firewall rule.
 */
class AB_WAFSmokeTest extends WAFTestCase
{
    /**
     * Tests direct access to the waf, without the test id cookie: an HTTP 400 response is expected
     */
    #[DataProvider('wafTestsDataProvider')]
    public function testWAFAsUpstreamNoTestCookie(string|null $clientType = null, string $wafScheme = 'http'): void
    {
        $clientOptions = ['base_uri' => static::getWAFBaseUri()];
        if ($wafScheme === 'unix') {
            $clientOptions['bindto'] = $_ENV['WAF_SOCKET'];
        }
        $client = $this->getClient($clientOptions, ['client_type' => $clientType]);
        $response = $client->request('GET', static::getWAFPath());
        // Note that in case of php errors, the status code will be 200 when display_errors in php.ini is on, and 500 when it is off
        $this->assertResponseHasStatusCode(400, $response, $response->getContent(false));
        $this->assertSame('This url can only be accessed by the test suite', $response->getContent(false));
    }

    /**
     * Tests direct access to the waf, with the test id cookie: an HTTP 403 response is expected
     */
    #[DataProvider('wafTestsDataProvider')]
    public function testWAFAsUpstreamWithTestCookie(string|null $clientType = null, string $wafScheme = 'http'): void
    {
        $clientOptions = ['base_uri' => static::getWAFBaseUri()];
        if ($wafScheme === 'unix') {
            $clientOptions['bindto'] = $_ENV['WAF_SOCKET'];
        }
        // NB: we do _not_ want to use $this->getTestClient here
        $client = ServerTestCase::getTestClient($clientOptions, ['client_type' => $clientType]);
        $response = $client->request('GET', static::getWAFPath());
        $this->assertResponseIsWAFDenial($response, $response->getContent(false));
    }

    /**
     * Tests access to the upstream server via the waf, with the test id cookie but no access rules defined: an HTTP 403 response is expected
     * @todo this test could also have an $upstreamClientType arg
     */
    #[DataProvider('wafTestsDataProvider')]
    public function testWAFAsWAFWithoutRules(string|null $clientType = null, string $wafScheme = 'http'): void
    {
        $response = $this->request([], 'GET', '', ['client_type' => $clientType, 'proxy_scheme' => $wafScheme]);
        // Without any config, the firewall should return a DENY response
        $this->assertResponseIsWAFDenial($response, $response->getContent(false));
    }

    public static function wafTestsDataProvider(): array
    {
        $out = [];
        foreach (self::getSupportedWAFSchemes() as $wafScheme) {
            foreach (self::getSupportedClientTypes() as $clientType) {
                if ($wafScheme === 'unix' && $clientType === 'native') {
                    continue;
                }
                $out[] = [$clientType, $wafScheme];
            }
        }
        return $out;
    }
}
