<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

abstract class ProxyTestCase extends ServerTestCase
{
    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @throws \Exception
     */
    protected function request(array $requestOptions, string $method = 'GET', string $path = '', array $testOptions = []): ResponseInterface
    {
        $client = $this->getProxyClient([], $testOptions);
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $uri = $path;
        } else {
            $uri = static::getServerBaseUri() . (trim($path) === '' ? static::getServerPath() : $path);
        }
        return $client->request($method, $uri, $requestOptions);
    }

    /**
     * Creates an http client with the given options, making its requests go _through_ the proxy
     * @throws \Exception
     * @todo check and add if needed support for tests iterating over http features, such as req/resp compression, charsets, etc...
     */
    protected function getProxyClient(array $clientOptions = [], array $testOptions = []): HttpClientInterface
    {
        $clientOptions = [
            'proxy' => static::getProxyBaseUri(),
        ] + $clientOptions;
        if (@$testOptions['proxy_scheme'] === 'unix') {
            $clientOptions['bindto'] = $_ENV['PROXY_SOCKET'];
        }
        if (@$testOptions['upstream_client_type'] !== null) {
            $clientOptions['headers'] = ['X-WAFCORE-Upstream-Client-Type' => $testOptions['upstream_client_type']] + ($clientOptions['headers'] ?? []);
        }
        if (@$testOptions['server_scheme'] !== null) {
            $clientOptions['headers'] = ['X-WAFCORE-Upstream-Scheme' => $testOptions['server_scheme']] + ($clientOptions['headers'] ?? []);
            unset($testOptions['server_scheme']);
        }

        return $this->getTestClient($clientOptions, $testOptions);
    }

    /**
     * Creates an http client with the given options, adding to its requests the cookies and custom http headers used by
     * the test proxy page to drive its operations. Useful basically to test direct access to the proxy page.
     * @throws \Exception
     */
    protected function getTestClient(array $clientOptions = [], array $testOptions = []): HttpClientInterface
    {
        $clientOptions['headers'] = [
            'X-WAFCORE-Server-Type' => $_ENV['SERVER_TYPE'],
            'X-WAFCORE-Log-File' => $this->testId . '.log',
            'X-WAFCORE-Trace-File' => $this->testId . '.trace',
        ] + ($clientOptions['headers'] ?? []);

        return parent::getTestClient($clientOptions, $testOptions);
    }

    /**
     * @throws \Exception
     */
    protected static function getProxyBaseUri(string $scheme = 'http'): string
    {
        switch ($scheme) {
            case 'http':
            case 'https':
                return static::buildUrl([
                    'scheme' => $scheme,
                    'host' => $_ENV['PROXY_HOST'],
                    'port' => $_ENV['PROXY_PORT'],
                ]);
            //case 'unix':
            //    return 'unix:' . $_ENV['PROXY_SOCKET'];
            default:
                throw new \InvalidArgumentException("Unsupported proxy scheme: $scheme");
        }
    }

    /**
     * Only to be used for accessing the proxy endpoint directly
     */
    protected static function getProxyPath(): string
    {
        return $_ENV['PROXY_PATH'];
    }

    /// @todo can we find a better name?
    public static function getCommonDataProviderOptions(): array
    {
        $out = [];
        foreach (self::getSupportedServerSchemes() as $serverScheme) {
            foreach (self::getSupportedProxyClientTypes() as $upstreamClientType) {
                // so far the only upstream client which we can successfully configure to bind to a socket is the sfhc curl one
                if ($serverScheme === 'unix' && ($upstreamClientType === 'guzzle' || $upstreamClientType === 'guzzle_stream' || $upstreamClientType === 'sfhc_native')) {
                    continue;
                }
                foreach (self::getSupportedProxySchemes() as $proxyScheme) {
                    foreach (self::getSupportedClientTypes() as $clientType) {
                        // the sfhc can talk to unix sockets only when using curl
                        if ($proxyScheme === 'unix' && $clientType === 'native') {
                            continue;
                        }
                        $out[] = [$clientType, $proxyScheme, $upstreamClientType, $serverScheme];
                    }
                }
            }
        }
        return $out;
    }

    protected static function getSupportedProxySchemes(): array
    {
        $schemes = [];
        if (isset($_ENV['PROXY_HOST']) && trim($_ENV['PROXY_HOST']) !== '') {
            $schemes[] = 'http';
        }
        if (isset($_ENV['PROXY_SOCKET']) && trim($_ENV['PROXY_SOCKET']) !== '') {
            $schemes[] = 'unix';
        }
        return $schemes;
    }

    /**
     * NB: we _presume_ that the proxy used to run the tests has installed php-curl, sf-http-client and guzzle
     * @return string[]
     */
    protected static function getSupportedProxyClientTypes(): array
    {
        return ['sfhc_native', 'sfhc_curl', 'guzzle', 'guzzle_stream'];
    }

    protected static function getRuleBasedTestDataProviderOptions(string $method, string $status): array
    {
        $rootDir = __DIR__ . "/configs/matchers/$method/$status/";
        $out = [];
        foreach (scandir($rootDir) as $fileName) {
            if (is_file($rootDir . $fileName) && str_ends_with($fileName, '.json')) {
                foreach (self::getCommonDataProviderOptions() as $opts) {
                    $out[] = array_merge(["matchers/$method/$status/$fileName"], $opts);
                }
            }
        }
        return $out;
    }

    protected function assertResponseHasKnownArrayBody(ResponseInterface $response, string $message = ''): array
    {
        $body = parent::assertResponseHasKnownArrayBody($response, $message);

        $this->assertResponseHeaderContains('Via', 'WAFCore', $response, $message);
        $this->assertStringContainsString('WAFCore', $body['getHeadersFromServer']['Via']);
        $this->assertStringContainsString('WAFCore', $body['getHeadersFromServer']['User-Agent']);

        return $body;
    }

    protected function assertResponseIsProxyDenial(ResponseInterface $response, string $message = ''): void
    {
        // Note that in case of php errors, the status code will be 200 when display_errors in php.ini is on, and 500 when it is off
        $this->assertResponseHasStatusCode(TestProxy::ACCESS_DENIED_STATUS_CODE, $response, $message);
        $this->assertResponseHasGivenArrayBody(TestProxy::ACCESS_DENIED_RESPONSE, $response, $message);
        $this->assertResponseHeaderContains('Via', 'WAFCore', $response, $message);
    }

    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    protected function getTestDetails(ResponseInterface $response): string
    {
        $out = '';
        if (@$_ENV['VERBOSITY'] >= 1) {
            $out .= $this->getProxyRequestTrace();
            if ($out != '') {
                $out = "\nRequest received by the proxy (and possibly response generated):\n$out";
            } else {
                $out = (string)$out;
            }
            $log = $this->getProxyTestLog();
            if ($log != '') {
                $out .= "\nServer log:\n$log";
            }
            $out .= "\nResponse received by the test code:\n" . $this->response2Log($response) . "\n";

            /// @todo... also check the error-log file of the webserver under test (if known) - if its modification date is "now",
            ///          it most likely means that there were server-side php errors or warnings
        }

        return $out;
    }

    protected function getProxyRequestTrace(): string|null|false
    {
        $serverSideTraceFile = sys_get_temp_dir() . '/' . $this->testId . '.trace';
        if (file_exists($serverSideTraceFile)) {
            return file_get_contents($serverSideTraceFile);
        }
        return null;
    }

    protected function getProxyTestLog(): string|null|false
    {
        $serverSideLogFile = sys_get_temp_dir() . '/' . $this->testId . '.log';

        if (file_exists($serverSideLogFile)) {
            return file_get_contents($serverSideLogFile);
        }
        return null;
    }
}
