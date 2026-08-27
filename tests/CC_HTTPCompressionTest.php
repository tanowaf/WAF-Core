<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use TanoWAF\WAFCore\Http\BodyCompressorTrait;

/**
 * Tests the waf ability to deal with compressed bodies.
 */
class CC_HTTPCompressionTest extends WAFTestCase
{
    use BodyCompressorTrait;

    #[DataProvider('passingCompressionRulesDataProvider')]
    public function testPassingCompressionRules(string $configFileName, string|null $clientType = null, string $wafScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http', string $clientAcceptEncoding = '', string $wafAcceptEncoding = '')
    {
        $acceptedCompressionHeaders = [];
        if ($clientAcceptEncoding !== '') {
            $acceptedCompressionHeaders = ['Accept-Encoding' => $clientAcceptEncoding];
        }

        $response = $this->request(
            [
                'headers' => [
                    'X-WAFCORE-Config-File' => $configFileName,
                    'X-WAFCORE-Force-Accept-Encoding' => $wafAcceptEncoding
                ] + $acceptedCompressionHeaders
            ],
            'GET',
            static::getServerPath(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $wafScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(200, $response, $failureMessage);
            $ceHeader = $this->getResponseHeader('Content-Encoding', $response);
            if ($ceHeader && $ceHeader[0] != 'identity' &&
                // this condition takes into account the Symfony HTTP Client adding on its own an `accept-encoding: gzip`
                // header, then decoding the response but not removing the response content-encoding header (see issue
                // https://github.com/symfony/symfony/issues/64869)
                ($clientAcceptEncoding !== '' || $ceHeader[0] !== 'gzip')) {
                $body = $this->decompressPayload($response->getContent(false), $ceHeader, [], $errorMessage);
                $this->assertIsString($body, (string)$errorMessage . "\n" . $failureMessage);
                /// @todo add support for application/php-serialized+base64
                $result = json_decode($body, true);
            } else {
                $result = $this->responseBodyToArray($response);
            }
            $this->assertIsArray($result, $failureMessage);
            $this->assertSame(TestServer::DEFAULT_RESPONSE['result'], $result['result'], $failureMessage);
            // NB: for this to work, the target webserver has to be set up to serve gzip-compressed responses
            if ($wafAcceptEncoding === 'gzip' && in_array($clientAcceptEncoding, ['', '*', 'gzip'])) {
/// @todo... figure out why this does not work with the current nginx setup (funnily enough, 403 responses do get compressed by it...)
/// @todo... figure out why this does not work with the current swoole setup
                if ($_ENV['SERVER_TYPE'] !== 'nginx' && $_ENV['SERVER_TYPE'] !== 'swoole') {
                    $this->assertGreaterThan(0, count($ceHeader), $failureMessage);
                    $this->assertSame('gzip', $ceHeader[0], $failureMessage);
                }
            }
        } catch (ExceptionInterface $e) {
            $this->assertSame(200, null, 'Exception thrown by the test client while communicating to the waf: ' . $e->getMessage());
        }
    }

    public static function passingCompressionRulesDataProvider(): array
    {
        $out = [];
        foreach (self::getRuleBasedTestDataProviderOptions('compression', 'passing') as $args) {
            foreach (self::getClientAllowedCompressionSchemes() as $clientAllowedCompressionScheme) {
                foreach (self::getWAFAllowedCompressionSchemes() as $wafAllowedCompressionScheme) {
                    $out[] = array_merge($args, [$clientAllowedCompressionScheme, $wafAllowedCompressionScheme]);
                }
            }
        }
        return $out;
    }

    #[DataProvider('failingCompressionRulesDataProvider')]
    public function testFailingCompressionRules(string $configFileName, string|null $clientType = null, string $wafScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http', string $clientAcceptEncoding = '', string $wafAcceptEncoding = '')
    {
        $acceptedCompressionHeaders = [];
        if ($clientAcceptEncoding !== '') {
            $acceptedCompressionHeaders = ['Accept-Encoding' => $clientAcceptEncoding];
        }

        $response = $this->request(
            [
                'headers' => [
                    'X-WAFCORE-Config-File' => $configFileName,
                    'X-WAFCORE-Force-Accept-Encoding' => $wafAcceptEncoding
                ] + $acceptedCompressionHeaders
            ],
            'GET',
            static::getServerPath(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $wafScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(TestWAF::ACCESS_DENIED_STATUS_CODE, $response, $failureMessage);
            $responseHeaders = $response->getHeaders(false);
            if (isset($responseHeaders['content-encoding']) && $responseHeaders['content-encoding'][0] != 'identity' &&
                // this condition takes into account the Symfony HTTP Client adding on its own an `accept-encoding: gzip`
                // header, then decoding the response but not removing the response content-encoding header (see issue
                // https://github.com/symfony/symfony/issues/64869)
                ($clientAcceptEncoding !== '' || $responseHeaders['content-encoding'][0] !== 'gzip')) {
                $body = $this->decompressPayload($response->getContent(false), $responseHeaders['content-encoding'], [], $errorMessage);
                $this->assertIsString($body, (string)$errorMessage . "\n" . $failureMessage);
                /// @todo add support for application/php-serialized+base64
                $result = json_decode($body, true);
            } else {
                $result = $this->responseBodyToArray($response);
            }
            $this->assertIsArray($result, $failureMessage);
            $this->assertSame(TestWAF::ACCESS_DENIED_RESPONSE, $result, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestWAF::ACCESS_DENIED_STATUS_CODE, null, 'Exception thrown by the test client while communicating to the waf: ' . $e->getMessage());
        }
    }

    public static function failingCompressionRulesDataProvider(): array
    {
        $out = [];
        foreach (self::getRuleBasedTestDataProviderOptions('compression', 'failing') as $args) {
            foreach (self::getClientAllowedCompressionSchemes() as $clientAllowedCompressionScheme) {
                foreach (self::getWAFAllowedCompressionSchemes() as $wafAllowedCompressionScheme) {
                    $out[] = array_merge($args, [$clientAllowedCompressionScheme, $wafAllowedCompressionScheme]);
                }
            }
        }
        return $out;
    }

    #[DataProvider('passingRequestCompressionRulesDataProvider')]
    public function testPassingRequestCompressionRules(string $configFileName, string|null $clientType = null, string $wafScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http', string $requestEncoding = '', string $verb = 'POST')
    {
        $requestCompressionHeaders = [];
        if (! in_array($requestEncoding, ['', 'none', 'identity'])) {
            $requestCompressionHeaders = ['Content-Encoding' => $requestEncoding];
        }

        $response = $this->request(
            [
                'headers' => [
                    'X-WAFCORE-Config-File' => $configFileName,
                    'X-WAFCORE-Force-Accept-Encoding' => 'identity',
                    'Content-Type' => 'application/json'
                ] + $requestCompressionHeaders,
                'body' => $this->getRequestBody($requestEncoding)
            ],
            $verb,
            static::getServerPath(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $wafScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(200, $response, $failureMessage);
            $data = $this->responseBodyToArray($response);
            $this->assertSame(TestServer::DEFAULT_RESPONSE['result'], $data['result'], $failureMessage);
            $this->assertSame(['test' => 'localhost'], $data['requestBody'], $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(200, null, 'Exception thrown by the test client while communicating to the waf: ' . $e->getMessage());
        }
    }

    public static function passingRequestCompressionRulesDataProvider(): array
    {
        $out = [];
        foreach (self::getRuleBasedTestDataProviderOptions('request_compression', 'passing') as $args) {
            foreach (self::getAllowedRequestCompressionSchemes() as $requestCompressionScheme) {
                foreach (self::getAllowedRequestVerbs() as $requestVerb) {
                    $out[] = array_merge($args, [$requestCompressionScheme, $requestVerb]);
                }
            }
        }
        return $out;
    }

    #[DataProvider('failingRequestCompressionRulesDataProvider')]
    public function testFailingRequestCompressionRules(string $configFileName, string|null $clientType = null, string $wafScheme = 'http',
        string|null $upstreamClientType = null, string $serverScheme = 'http', string $requestEncoding = '', string $verb = 'POST')
    {
        $requestCompressionHeaders = [];
        if (! in_array($requestEncoding, ['', 'none', 'identity'])) {
            $requestCompressionHeaders = ['Content-Encoding' => $requestEncoding];
        }
        $response = $this->request(
            [
                'headers' => [
                    'X-WAFCORE-Config-File' => $configFileName,
                    'X-WAFCORE-Force-Accept-Encoding' => 'identity',
                    'content-type' => 'application/json'
                ] + $requestCompressionHeaders,
                'body' => $this->getRequestBody($requestEncoding)
            ],
            $verb,
            static::getServerPath(),
            ['client_type' => $clientType, 'upstream_client_type' => $upstreamClientType, 'proxy_scheme' => $wafScheme, 'server_scheme' => $serverScheme]
        );
        try {
            $failureMessage = $this->getTestDetails($response);
            $this->assertResponseHasStatusCode(TestWAF::ACCESS_DENIED_STATUS_CODE, $response, $failureMessage);
            $this->assertResponseHasGivenArrayBody(TestWAF::ACCESS_DENIED_RESPONSE, $response, $failureMessage);
        } catch (ExceptionInterface $e) {
            $this->assertSame(TestWAF::ACCESS_DENIED_RESPONSE, null, 'Exception thrown by the test client while communicating to the waf: ' . $e->getMessage());
        }
    }

    public static function failingRequestCompressionRulesDataProvider(): array
    {
        $out = [];
        foreach (self::getRuleBasedTestDataProviderOptions('request_compression', 'failing') as $args) {
            foreach (self::getAllowedRequestCompressionSchemes() as $requestCompressionScheme) {
                foreach (self::getAllowedRequestVerbs() as $requestVerb) {
                    $out[] = array_merge($args, [$requestCompressionScheme, $requestVerb]);
                }
            }
        }
        return $out;
    }

    protected function getRequestBody(string $requestCompressionScheme): string
    {
        $out = json_encode(['test' => 'localhost']);

        switch ($requestCompressionScheme) {
            case 'br':
            case 'deflate':
            case 'gzip':
            case 'zstd':
                $out = $this->compressPayload($out, [$requestCompressionScheme], $actualScheme);
                $this->assertSame($requestCompressionScheme, $actualScheme, "Failed to compress the request to desired scheme '$requestCompressionScheme'");
                break;
            case '':
            case 'identity':
            case 'none':
                break;
            default:
                throw new \InvalidArgumentException("Unsupported request compression scheme: '$requestCompressionScheme'");
        }

        return $out;
    }

    /**
     * @return string[] '' for "do not mess with defaults", 'none' for "please remove accept-encodings headers"
     * @todo... test also compression schemes with weights
     */
    protected static function getWAFAllowedCompressionSchemes(): array
    {
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Accept-Encoding
        // @see https://www.iana.org/assignments/http-parameters/http-parameters.xhtml
        // We might as well drop 'deflate', as that is not supported by Apache, because of flaky support by browsers
        // and most likely also neither by Nginx nor FrankenPHP (see https://zlib.net/zlib_faq.html#faq39)
        /// @todo add support for dcb, dcz (brotli) if the relevant php extensions are available
        $schemes = ['', 'none', 'identity', '*', 'compress', 'gzip', 'deflate'];

        /// @todo ideally check for function existence waf-side for decoding it, and upstream-server-side for serving
        ///       it (or do the server-side encoding in php))
        if ($_ENV['SERVER_TYPE'] !== 'frankenphp') {
            if (function_exists('brotli_uncompress')) {
                $schemes[] = 'br';
            }
            if (function_exists('zstd_uncompress')) {
                $schemes[] = 'zstd';
            }
        }
        return $schemes;
    }

    /**
     * @return string[] '' for "do not mess with defaults", 'none' for "please remove accept-encodings headers"
     * @todo... test also compression schemes with weights
     */
    protected static function getClientAllowedCompressionSchemes(): array
    {
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Accept-Encoding
        // @see https://www.iana.org/assignments/http-parameters/http-parameters.xhtml
        // We might as well drop 'deflate', as that is not supported by Apache, because of flaky support by browsers
        // and most likely also neither by Nginx nor FrankenPHP (see https://zlib.net/zlib_faq.html#faq39)
        /// @todo add 'compress', br, dcb, dcz (brotli), zstd if the relevant php extensions are available (ideally check both
        ///       client-side and waf-side)
        return ['', '*', 'identity', 'gzip', 'deflate'];
    }

    /**
     * @return string[]
     */
    protected static function getAllowedRequestCompressionSchemes(): array
    {
        /// @todo... add tests for 'compress'
        $schemes = ['', 'identity', 'gzip', 'deflate'];
        /// @todo either find a way to have brotli, zstd enabled on frankenphp, or ask the waf server for their availability
        if ($_ENV['SERVER_TYPE'] !== 'frankenphp') {
            if (function_exists('brotli_uncompress')) {
                $schemes[] = 'br';
            }
            if (function_exists('zstd_uncompress')) {
                $schemes[] = 'zstd';
            }
        }
        return $schemes;
    }

    /**
     * @return string[]
     */
    protected static function getAllowedRequestVerbs(): array
    {
        return ['POST', 'PUT'];
    }
}
