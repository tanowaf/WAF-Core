<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/// @todo declare dependency on SmokeTest
class CA_MatchingTest extends ProxyTestCase
{
    static protected int $clientPort = 31000;

    #[DataProvider('invalidRulesDataProvider')]
    public function testInvalidRules(string $configAsString, string|null $clientType = null, string $proxyScheme = 'http',
       string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config' => $configAsString]],
            'GET',
            '',
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(TestProxy::ERROR_STATUS_CODE, $response, $failureMessage);
            $this->assertArrayIsEqualToArrayIgnoringListOfKeys(TestProxy::ERROR_RESPONSE, $this->responseBodyToArray($response), ['message', 'file', 'line'], $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ERROR_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    public static function invalidRulesDataProvider(): array
    {
        $strings = [
            // not an array of rules
            'null',
            'true',
            'false',
            '0',
            '1',
            '1.5',
            'not a json array string',
            // rule 1 is not an array
            '{"rule 1": true}',
            '{"rule 1": 0}',
            '{"rule 1": "a string"}',
            // rule 1 is an array with an invalid body
            '{"rule 1": ["whatever"]}',
            '{"rule 1": {"whatever": true}}',

            // bad req_match
            '{"rule 1": {"req_match": true}}',
            '{"rule 1": {"req_match": 0}}',
            '{"rule 1": {"req_match": {"zzz": true}}}}',
            // bad resp_match
            '{"rule 1": {"req_match": []}}',
            '{"rule 1": {"resp_match": true}}',
            '{"rule 1": {"resp_match": 0}}',
            '{"rule 1": {"resp_match": {"zzz": true}}}}',
            '{"rule 1": {"resp_match": []}}',
            // bad req_filters
            '{"rule 1": {"req_filters": true}}',
            '{"rule 1": {"req_filters": 0}}',
            '{"rule 1": {"req_filters": {"zzz": true}}}}',
            '{"rule 1": {"req_filters": []}}',
            // bad resp_filters
            '{"rule 1": {"resp_filters": true}}',
            '{"rule 1": {"resp_filters": 0}}',
            '{"rule 1": {"resp_filters": {"zzz": true}}}}',
            '{"rule 1": {"resp_filters": []}}',
            // no matchers
            '{"rule 1": {"req_match": [], "req_action": "allow"}}',
            '{"rule 1": {"req_match": [], "req_action": "deny"}}',
            '{"rule 1": {"resp_match": [], "resp_action": "allow"}}',
            '{"rule 1": {"resp_match": [], "resp_action": "deny"}}',
            '{"rule 1": {"req_match": [], "resp_match": []}}',
            // bad combos
            '{"rule 1": {"req_match": {"host": "localhost"}, "req_action": "unknown"}}',
            '{"rule 1": {"req_match": {"host": "localhost"}, "req_action": "deny", "req_filters: ["one"]}}',
            '{"rule 1": {"req_match": {"host": "localhost"}, "req_action": "deny", "resp_match: {"body": "whatever"}}}',
            '{"rule 1": {"req_match": {"host": "localhost"}, "req_action": "deny", "resp_filters: ["one"]}}',
            '{"rule 1": {"req_match": {"host": "localhost"}, "req_action": "deny", "resp_action: "deny"}}',
            '{"rule 1": {"resp_match": {"body": "whatever"}}}',
            '{"rule 1": {"resp_match": {"body": "whatever"}, "resp_action": "deny, "resp_filters: ["one"]"}}',
        ];
        $out = [];
        /// @todo is this really necessary? We could just pick one type of client / proxy / server for these tests...
        foreach (self::getCommonDataProviderOptions() as $opts) {
            foreach ($strings as $string) {
                $out[] = array_merge([$string], $opts);
            }
        }
        return $out;
    }

    #[DataProvider('passingGetRulesDataProvider')]
    public function testPassingGetRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
       string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        // skip test cases which are bound to fail with given configs
        /// @todo this should be more robust/flexible... We should allow the json configs to specify excluded test configs...
        if ($proxyScheme === 'unix' && in_array(basename($configFileName), [
            '001_client_address_fixed.json', '003_client_address_many.json',
        ])) {
            // avoid the line noise from the skipped test
            //$this->markTestSkipped('Can not test a client_address match when running the proxy on a unix socket');
            $this->assertSame(0, 0);
            return;
        }

        $response = $this->request(
            ['headers' => ['X-YAWAF-Config-File' => $configFileName, 'X-YAWAF-Force-Accept-Encoding' => 'identity'] + $this->getCommonRequestHeaders()],
            'GET',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(200, $response, $failureMessage);
            $this->assertResponseHasKnownArrayBody($response, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(200, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    public static function passingGetRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('get', 'passing');
    }

    #[DataProvider('failingGetRulesDataProvider')]
    public function testFailingGetRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config-File' => $configFileName, 'X-YAWAF-Force-Accept-Encoding' => 'identity'] + $this->getCommonRequestHeaders()],
            'GET',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $this->assertResponseIsProxyDenial($response, $this->getTestDetails($response));
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    public static function failingGetRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('get', 'failing');
    }

/// @todo... add test cases sending requests which populate $_POST, and check for that in the returned data
/// @todo... add test cases sending requests which populate file uploads, and check for that in the returned data
    #[DataProvider('passingPostRulesDataProvider')]
    public function testPassingPostRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        /// @todo move the request body to a config file
        $response = $this->request(
            [
                'headers' => [
                    'X-YAWAF-Config-File' => $configFileName,
                    'X-YAWAF-Force-Accept-Encoding' => 'identity',
                    'Content-Type' => 'application/json'
                ] + $this->getCommonRequestHeaders(),
                'body' => json_encode(['test' => 'localhost'])
            ],
            'POST',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(200, $response, $failureMessage);
            $data = $this->responseBodyToArray($response);
            $this->assertSame(TestServer::DEFAULT_RESPONSE['result'], $data['result'], $failureMessage);
            $this->assertSame(['test' => 'localhost'], $data['requestBody'], $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(200, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    public static function passingPostRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('post', 'passing');
    }

/// @todo... add test cases sending requests which populate $_POST, $_FILES
    #[DataProvider('failingPostRulesDataProvider')]
    public function testFailingPostRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        /// @todo move the request body to a config file
        $response = $this->request(
            [
                'headers' => [
                    'X-YAWAF-Config-File' => $configFileName,
                    'X-YAWAF-Force-Accept-Encoding' => 'identity',
                    'Content-Type' => 'application/json'
                ] + $this->getCommonRequestHeaders(),
                'body' => json_encode(['test' => 'localhost'])
            ],
            'POST',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $this->assertResponseIsProxyDenial($response, $this->getTestDetails($response));
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    public static function failingPostRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('post', 'failing');
    }

    #[DataProvider('getCommonDataProviderOptions')]
    public function testPortMatcher(string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        // skip test cases which are bound to fail with given configs
        /// @todo use a custom DataProvider
        if ($proxyScheme === 'unix') {
            $this->assertSame(0, 0);
            return;
        }

        $rule = [['port' => ($_ENV['HTTPSERVER_PORT'] != '' ? $_ENV['HTTPSERVER_PORT'] : 80)]];
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config' => json_encode($rule)] + $this->getCommonRequestHeaders()],
            'GET',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(200, $response, $failureMessage);
            $this->assertResponseHasKnownArrayBody($response, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(200, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    #[DataProvider('getCommonDataProviderOptions')]
    public function testPortMatcherFail(string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        // skip test cases which are bound to fail with given configs
        /// @todo use a custom DataProvider
        if ($proxyScheme === 'unix') {
            $this->assertSame(0, 0);
            return;
        }

        $rule = [['port' => ($_ENV['HTTPSERVER_PORT'] != '' ? ($_ENV['HTTPSERVER_PORT'] + 1) : 79)]];
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config' => json_encode($rule)] + $this->getCommonRequestHeaders()],
            'GET',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $this->assertResponseIsProxyDenial($response, $this->getTestDetails($response));
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    #[DataProvider('getCommonDataProviderOptions')]
    public function testPathMatcher(string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        $rule = [['url_path/no_wildcards' => $_ENV['HTTPSERVER_PATH']]];
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config' => json_encode($rule)] + $this->getCommonRequestHeaders()],
            'GET',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(200, $response, $failureMessage);
            $this->assertResponseHasKnownArrayBody($response, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(200, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }

        $rule = [['url_path/no_wildcards' => $_ENV['HTTPSERVER_PATH'] . '/yolo']];
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config' => json_encode($rule)] + $this->getCommonRequestHeaders()],
            'GET',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $this->assertResponseIsProxyDenial($response, $this->getTestDetails($response));
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    #[DataProvider('getCommonDataProviderOptions')]
    public function testPathMatcherFail(string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        $rule = [['url_path/no_wildcards' => $_ENV['HTTPSERVER_PATH'] . '/yolo']];
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config' => json_encode($rule)] + $this->getCommonRequestHeaders()],
            'GET',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $this->assertResponseIsProxyDenial($response, $this->getTestDetails($response));
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    #[DataProvider('getCommonDataProviderOptions')]
    public function testClientPortMatcher(string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        if (isset($_SERVER['GITHUB_ACTIONS'])) {
            $this->markTestSkipped('Client Port Matching testing is unreliable on GitHub. Skipping it...');
        }

        // skip test cases which are bound to fail with given configs
        /// @todo use a custom DataProvider
        if ($proxyScheme === 'unix' || $clientType === 'native') {
            $this->assertSame(0, 0);
            return;
        }

        // NB: we try to make sure that the port is not use, by increasing it on every pass of the test.
        // Atm this kind "generally" works, helped by the fact that we tell curl to use `Connection: close` for this test,
        // which means connections getting closed immediately after use instead of being kept open for reuse.
        // Nonetheless, there is no real guarantee that self::$clientPort + 1 is available at this very moment...
        self::$clientPort += 1;

/// @todo... using http 1.0 makes the tests fail with nginx, which returns a 400 response and complains that
///              `client sent HTTP/1.0 request with "Transfer-Encoding" header`.
///          This "might be happening" only when the underlying http client in use is curl (as used by both Guzzle and Symfony),
///          and seems hard to trace - as far as the php script of the upstream server is concerned, there is no
///          Transfer-Encoding in the headers it receives from the webserver... We should use a proper sniffer such
///          as Wireshark to figure out if the issue lies in the proxy client code, in curl, between the proxy php code and
///          nginx, within nginx, or between nginx and fpm

        $rule = [['client_port' => self::$clientPort]];
        $response = $this->request(
            [
                'headers' => ['X-YAWAF-Config' => json_encode($rule), 'Connection' => 'close'] + $this->getCommonRequestHeaders(),
                'bindto' => '127.0.0.1:' . self::$clientPort
            ],
            'GET',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(200, $response, $failureMessage);
            $this->assertResponseHasKnownArrayBody($response, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(200, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    #[DataProvider('getCommonDataProviderOptions')]
    public function testClientPortMatcherFail(string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        // skip test cases which are bound to fail with given configs
        /// @todo use a custom DataProvider
        if ($proxyScheme === 'unix' || $clientType === 'native') {
            $this->assertSame(0, 0);
            return;
        }

        // the server port can not be the client port :-D
        $rule = [['client_port' => $_ENV['HTTPSERVER_PORT']]];
        $response = $this->request(
            [
                'headers' => ['X-YAWAF-Config' => json_encode($rule)] + $this->getCommonRequestHeaders(),
            ],
            'GET',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $this->assertResponseIsProxyDenial($response, $this->getTestDetails($response));
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    #[DataProvider('passingHeadRulesDataProvider')]
    public function testPassingHeadRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config-File' => $configFileName] + $this->getCommonRequestHeaders()],
            'HEAD',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(200, $response, $failureMessage);
            // NB: responses to HEAD requests _can_ (and presumably should) have a content-length and content-type header,
            // but they should carry no body
            $this->assertEquals('', $response->getContent(false));
        } catch (ExceptionInterface $e) {
            $this->assertSame(200, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    public static function passingHeadRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('head', 'passing');
    }

    #[DataProvider('failingHeadRulesDataProvider')]
    public function testFailingHeadRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config-File' => $configFileName] + $this->getCommonRequestHeaders()],
            'HEAD',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            // NB: the SF HTTP Client strips the body from responses to HEAD requests, even if the proxy sends it
            $this->assertResponseHasStatusCode(TestProxy::ACCESS_DENIED_STATUS_CODE, $response, $this->getTestDetails($response));
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    public static function failingHeadRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('head', 'failing');
    }

    #[DataProvider('passingOptionsRulesDataProvider')]
    public function testPassingOptionsRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
         string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config-File' => $configFileName] + $this->getCommonRequestHeaders()],
            'OPTIONS',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(204, $response, $failureMessage);
            $this->assertResponseHasHeader('allow', $response, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(204, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    public static function passingOptionsRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('options', 'passing');
    }

    #[DataProvider('failingOptionsRulesDataProvider')]
    public function testFailingOptionsRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
         string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        $response = $this->request(
            ['headers' => ['X-YAWAF-Config-File' => $configFileName] + $this->getCommonRequestHeaders()],
            'OPTIONS',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $this->assertResponseIsProxyDenial($response, $this->getTestDetails($response));
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
/// @todo... check that resp. body is empty... Should it be? Check the http spec!
    }

    public static function failingOptionsRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('options', 'failing');
    }

    #[DataProvider('passingTraceRulesDataProvider')]
    public function testPassingTraceRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        if ($_ENV['SERVER_TYPE'] !== 'frankenphp') {
            /// @todo... figure out how to allow TRACE requests with Apache and Nginx
            $this->markTestSkipped("Only frankenphp is currently set up to serve TRACE requests");
        }

        $response = $this->request(
            ['headers' => ['X-YAWAF-Config-File' => $configFileName] + $this->getCommonRequestHeaders()],
            'TRACE',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(200, $response, $failureMessage);
            $body = $response->getContent(false);
            $this->assertStringContainsString('TRACE ', $body);
            //$this->assertStringContainsString(urlencode($this->getCommonQueryString()), $body);
        } catch (ExceptionInterface $e) {
            $this->assertSame(200, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
    }

    public static function passingTraceRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('trace', 'passing');
    }

    #[DataProvider('failingTraceRulesDataProvider')]
    public function testFailingTraceRules(string $configFileName, string|null $clientType = null, string $proxyScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http')
    {
        if ($_ENV['SERVER_TYPE'] !== 'frankenphp') {
            /// @todo... figure out how to allow TRACE requests with Apache and Nginx
            $this->markTestSkipped("Only frankenphp is currently set up to serve TRACE requests");
        }

        $response = $this->request(
            ['headers' => ['X-YAWAF-Config-File' => $configFileName] + $this->getCommonRequestHeaders()],
            'TRACE',
            static::getServerPath() . '?' . $this->getCommonQueryString(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $proxyScheme, 'server_scheme' => $serverScheme]
        );

        try {
            $this->assertResponseIsProxyDenial($response, $this->getTestDetails($response));
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestProxy::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the proxy: ' . $e->getMessage());
        }
/// @todo... check that resp. body is empty... Should it be? Check the http spec!
    }

    public static function failingTraceRulesDataProvider(): array
    {
        return self::getRuleBasedTestDataProviderOptions('trace', 'failing');
    }

    protected function getCommonRequestHeaders(): array
    {
        // note: the commented-out headers are never used by any test case (rules in tests/config)
        return [
            'X-Test-1' => 'Hello',
            //'X-Test-2' => 1,
            'X-Test-3' => 0,
            //'X-Test-4' => 0.5,
            //'X-Test-5' => true,
            'X-Test-6' => false, // serialized as empty string
            'X-Test-7' => null,  // serialized as empty string
            'X-Test-8' => ['hi', 'there'],
            'X-Test-9' => '_ :;.\/"\'?!(){}[]@<>=-+*#$&`|~^%',
        ];
    }

    protected function getCommonQueryString(): string
    {
        /// @todo add a test case for `surprise` (and others?) then remove from this qs all params which are not used by any test case
        return 'testId=' .$this->testId . '&y=yes&n=no&true=true&false=false&1=1&0=0&0.1=0.1&array[]=one&array[]=two&surprise';
    }
}
