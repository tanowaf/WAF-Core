<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests access to the upstream webserver, without going through the waf.
 */
class AA_ServerSmokeTest extends ServerTestCase
{
    #[DataProvider('serverTestsDataProvider')]
    public function testServer(string|null $clientType = null, string $serverScheme = 'http'): void
    {
        $client = $this->getClient(['base_uri' => static::getServerBaseUri()], ['client_type' => $clientType, 'server_scheme' => $serverScheme]);
        $response = $client->request('GET', static::getServerPath());
        // Note that in case of php errors, the status code will be 200 when display_errors in php.ini is on, and 500 when it is off
        $this->assertResponseHasStatusCode(200, $response, $response->getContent(false));
        $this->assertArrayIsEqualToArrayIgnoringListOfKeys(TestServer::DEFAULT_RESPONSE, $this->responseBodyToArray($response), ['getallheaders', 'getHeadersFromServer', 'serverRequest']);
    }

    public static function serverTestsDataProvider(): array
    {
        $out = [];
        foreach (self::getSupportedServerSchemes() as $serverScheme) {
            foreach (self::getSupportedClientTypes() as $clientType) {
                if ($serverScheme === 'unix' && $clientType === 'native') {
                    continue;
                }
                $out[] = [$clientType, $serverScheme];
            }
        }
        return $out;
    }
}
