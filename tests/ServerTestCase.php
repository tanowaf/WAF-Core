<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Runner\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use TanoWAF\WAFCore\Tests\PhpunitSelenium\RemoteCoverageCollector;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/// @todo... bring back support for collecting code coverage of code executed via http calls
abstract class ServerTestCase extends TestCase
{
    protected string|null $testId;
    protected static string|null $randId;
    /** @var string[] */
    protected static array $testIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Set up a database connection or other fixture which needs to be available...

        $tmpDir = sys_get_temp_dir();

        foreach (scandir($tmpDir) as $fileName) {
            $filePath = "$tmpDir/$fileName";
            if (!is_file($filePath)) {
                continue;
            }
            /// @todo we could only remove files which match existing method names
            /// @todo we should also remove files which match existing method names without the _with_data_set__ from DataProviders
            if (preg_match('/^(test.+)_with_data_set__[0-9]+\.(:?log|trace)$/', $fileName)) {
                @unlink($filePath);
            }
        }

        self::$randId = uniqid();
        file_put_contents($tmpDir . '/phpunit_rand_id.txt', self::$randId);
    }

    public static function tearDownAfterClass(): void
    {
        /// @todo we could remove all /tmp .log and .trace files here, but we leave them available for manual inspection,
        ///       and remove them in setUpBeforeClass instead...

        if (is_file(sys_get_temp_dir() . '/phpunit_rand_id.txt')) {
            unlink(sys_get_temp_dir() . '/phpunit_rand_id.txt');
        }
        self::$randId = null;

        if (self::shouldCollectCodeCoverageInformation()) {
            self::retrieveRemoteCodeCoverage();
        }
        self::$testIds = [];

        parent::tearDownAfterClass();
    }

    public function setUp(): void
    {
        parent::setUp();

        // make the test name a nice filename
        $this->testId = str_replace([' ', '#'], '_', $this->nameWithDataSet());
        self::$testIds[] = $this->testId;
    }

    public function tearDown(): void
    {
        $this->testId = null;

        parent::tearDown();
    }

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
     * Creates an http client with the given options, making its requests go through the proxy
     * @throws \Exception
     * @todo check and add if needed support for tests iterating over http features, such as req/resp compression, charsets, etc...
     */
    protected function getProxyClient(array $clientOptions = [], array $testOptions = []): HttpClientInterface
    {
        $clientOptions = $clientOptions + [
            'proxy' => static::getProxyBaseUri(),
        ];
        if (@$testOptions['proxy_scheme'] === 'unix') {
            $clientOptions['bindto'] = 'unix:' . $_ENV['PROXY_SOCKET'];
        }
        if (@$testOptions['upstream_client_type'] !== null) {
            $clientOptions['headers'] = ['X-WAFCORE-Upstream-Client-Type' => $testOptions['upstream_client_type']] + ($clientOptions['headers'] ?? []);
        }
        if (@$testOptions['server_scheme'] !== null) {
            $clientOptions['headers'] = ['X-WAFCORE-Upstream-Scheme' => $testOptions['server_scheme']] + ($clientOptions['headers'] ?? []);
        }

        return $this->getTestClient($clientOptions, $testOptions);
    }

    /**
     * Creates an http client with the given options, adding to its requests the cookies and custom http headers used by
     * the test webserver page to drive its operations.
     * @throws \Exception
     */
    protected function getTestClient(array $clientOptions = [], array $testOptions = []): HttpClientInterface
    {
        $cookie = '';
        if (isset($clientOptions['headers']['Cookie'])) {
            $cookie = $clientOptions['headers']['Cookie'] . ';';
        }
        $cookie .= 'PHPUNIT_RANDOM_TEST_ID=' . self::$randId;
        if (self::shouldCollectCodeCoverageInformation()) {
            $cookie .= ';  PHPUNIT_SELENIUM_TEST_ID=' . $this->testId;
        }
        $clientOptions['headers'] = ['Cookie' => $cookie] + ($clientOptions['headers'] ?? []);

        return $this->getClient($clientOptions, $testOptions);
    }

    /**
     * Creates an http client with the given options, allowing to pick a preferred implementation.
     * @param array $clientOptions see Symfony\Contracts\HttpClient\HttpClientInterface
     * @param array $testOptions supported: client_type => curl/native/null
     * @throws \Exception
     */
    protected function getClient(array $clientOptions = [], array $testOptions = []): HttpClientInterface
    {
        if (@$testOptions['server_scheme'] === 'unix') {
            $clientOptions['bindto'] = $_ENV['HTTPSERVER_SOCKET'];
        }

        // avoid tests lasting too long in case of things going south - the test server is supposed to respond quickly in any case
        $clientOptions = $clientOptions + [
            /// @todo this will fail when testing with sfhc < 8.1. Enable this selectively
            //'max_connect_duration' => 1.0, // seconds
            'max_duration' => 4.0, // seconds: one more than the timeout of the proxy talking to upstream
        ];

        switch (@$testOptions['client_type']) {
            case 'curl':
                // the constructor already checks for the curl extension - no need to do it here
                return new CurlHttpClient($clientOptions);
            case 'native':
                return new NativeHttpClient($clientOptions);
            case null:
            case 'any':
                return HttpClient::create($clientOptions);
            default:
                throw new \InvalidArgumentException("Unsupported preferred client type: '{$testOptions['preferred_client_type']}'");
        }
    }

    /**
     * @throws \Exception
     */
    protected static function getServerBaseUri(string $scheme = 'http'): string
    {
        switch ($scheme) {
            case 'http':
            case 'https':
                return static::buildUrl([
                    'scheme' => $scheme,
                    'host' => $_ENV['HTTPSERVER_HOST'],
                    'port' => $_ENV['HTTPSERVER_PORT'],
                ]);
            //case 'unix':
            //    return 'unix:' . $_ENV['HTTPSERVER_SOCKET'];
            default:
                throw new \InvalidArgumentException("Unsupported server scheme: $scheme");
        }
    }

    protected static function getServerPath(): string
    {
        return $_ENV['HTTPSERVER_PATH'];
    }

    /**
     * @throws \Exception
     */
    protected static function getRemoteCoverageBaseUri(): string
    {
        /// @todo allow this to be set via an env var, fall back on server if that is not defined
        return static::getServerBaseUri();
    }

    protected static function getRemoteCoveragePath(): string
    {
        // @todo should we allow this to be set via an env var?
        return '/phpunit_coverage.php';
    }

    public static function clientTypesDataProvider(): array
    {
        $out = [];
        foreach (static::getSupportedClientTypes() as $type) {
            $out[] = [$type];
        }
        return $out;
    }

    /**
     * These are the types of symfony http clients used to query the server/proxy
     * @return string[]
     */
    protected static function getSupportedClientTypes(): array
    {
        return extension_loaded('curl') ? ['native', 'curl'] : ['native'];
    }

    protected static function getSupportedServerSchemes(): array
    {
        $schemes = [];
        if (isset($_ENV['HTTPSERVER_HOST']) && trim($_ENV['HTTPSERVER_HOST']) !== '') {
            $schemes[] = 'http';
        }
        if (isset($_ENV['HTTPSERVER_SOCKET']) && trim($_ENV['HTTPSERVER_SOCKET']) !== '') {
            $schemes[] = 'unix';
        }
        return $schemes;
    }

    protected function getTestDetails(ResponseInterface $response): string
    {
/// @todo...
        return '';
    }

    /**
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface On a 3xx when $throw is true and the "max_redirects" option has been reached
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface When a network error occurs
     */
    protected function response2Log(ResponseInterface $response): string
    {
        /// @todo can we improve the fidelity of the response dump? The SF responseInterface API does not allow us to grab
        ///       the protocol version, for example...
        $out = 'HTTP/x.y ' . $response->getStatusCode() . " ...\n";
        foreach ($response->getHeaders(false) as $name => $values) {
            $out .= ucwords($name, " \t\r\n\f\v-") . ': ' . implode(',', $values) . "\n";
        }
        $out .= "\n" . $response->getContent(false);
        return $out;
    }

    protected static function shouldCollectCodeCoverageInformation(): bool
    {
        return CodeCoverage::instance()->isActive();
    }

    protected static function retrieveRemoteCodeCoverage(): void
    {
        foreach (self::$testIds as $testId) {
            $collector = new RemoteCoverageCollector(
                static::getRemoteCoverageBaseUri() . static::getRemoteCoveragePath(),
                $testId
            );
            $data = $collector->get();
            if ($data) {
                CodeCoverage::instance()->codeCoverage()->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($data), $testId);
            }
        }
    }

    /**
     * Generate URL from its components (i.e., opposite of built-in php function, parse_url())
     */
    protected static function buildUrl(array $components): string
    {
        $url = ! empty($components['scheme']) ? $components['scheme'] . '://' : '';

        if ( ! empty($components['username']) && ! empty($components['password'])) {
            $url .= $components['username'] . ':' . $components['password'] . '@';
        }

        $url .= $components['host'] ??  '';

        if ( ! empty($components['port']) &&
            (($components['scheme'] === 'http' && $components['port'] !== 80) ||
                ($components['scheme'] === 'https' && $components['port'] !== 443))
        ) {
            $url .= ':' . $components['port'];
        }

        if ( ! empty($components['path'])) {
            $url .= $components['path'];
        }

        if ( ! empty($components['query'])) {
            $url .= '?' . http_build_query($components['query']);
        }

        if ( ! empty($components['fragment'])) {
            $url .= '#' . $components['fragment'];
        }

        return $url;
    }

    protected function getResponseHeader(string $headerName, ResponseInterface $response): array
    {
        $headers = $response->getHeaders(false);
        return $headers[strtolower($headerName)] ?? [];
    }

    protected function hasResponseHeader(string $headerName, ResponseInterface $response): bool
    {
        return array_key_exists(strtolower($headerName), $response->getHeaders(false));
    }

    protected function assertResponseHasStatusCode(int $expectedStatusCode, ResponseInterface $response, string $message = ''): void
    {
        $this->assertSame($expectedStatusCode, $response->getStatusCode(), $message);
    }

    protected function assertResponseHasHeader(string $headerName, ResponseInterface $response, string $message = ''): void
    {
        $this->assertArrayHasKey(strtolower($headerName), $response->getHeaders(false), $message);
    }

    protected function assertResponseHeaderSame(string $headerName, $value, ResponseInterface $response, string $message = ''): void
    {
        $headers = $response->getHeaders(false);
        $this->assertArrayHasKey(strtolower($headerName), $headers, $message);
        $this->assertSame($value, $headers[strtolower($headerName)][0], $message);
    }

    protected function assertResponseHeaderContains(string $headerName, $value, ResponseInterface $response, string $message = ''): void
    {
        $headers = $response->getHeaders(false);
        $this->assertArrayHasKey(strtolower($headerName), $headers, $message);
        $this->assertStringContainsString($value, $headers[strtolower($headerName)][0], $message);
    }


    protected function assertResponseHasGivenArrayBody($body, ResponseInterface $response, string $message = ''): void
    {
        $this->assertSame($body, $this->responseBodyToArray($response), $message);
    }

    protected function assertResponseHasKnownArrayBody(ResponseInterface $response, string $message = ''): array
    {
        $body = $this->responseBodyToArray($response);
        $this->assertIsArray($body, $message);
        /// @todo check more of the data in the response
        $this->assertSame(TestServer::DEFAULT_RESPONSE['result'], @$body['result'], $message);
        return $body;
    }

    protected function responseBodyToArray(ResponseInterface $response): mixed
    {
        $headers = $response->getHeaders(false);
        if (!array_key_exists('content-type', $headers)) {
            throw new \RuntimeException("Response has no content-type");
        }
        switch ($headers['content-type'][0]) {
            case 'application/json':
                $out = @json_decode($response->getContent(false), true);
                if (json_last_error()) {
                    throw new \RuntimeException("Error decoding json response: " . json_last_error_msg());
                }
                return $out;
            case 'application/php-serialized+base64':
                $out = base64_decode($response->getContent(false), true);
                if ($out === false) {
                    throw new \RuntimeException("Error decoding base64 response");
                }
                $out = @unserialize($out, ['allowed_classes' => false]);
                if ($out === false) {
                    throw new \RuntimeException("Error decoding serialized response");
                }
                return $out;
            default:
                throw new \RuntimeException("Cannot decode response body: unsupported content type: {$headers['content-type'][0]}");
        }
    }
}
