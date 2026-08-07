<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the ServerRequestCreator class for all kind of weird http input.
 * In fact these tests are more of a smoke-test for the webserver used to run PHP, how it handles malformed http requests,
 * and what it lets through to the application.
 *
 * @todo... more tests: - anomalies in the start line
 *                      - headers which have a known syntax, to check if the webservers strip the double quotes and comments
 *                      - unexpected values for Host header (incl. double Host)
 */
class BA_ServerRequestCreatorTest extends ServerTestCase
{
    /**
     * Test http headers which cause all (tested) servers to pass them on to PHP - single header
     */
    #[DataProvider('singletonHttpHeaderDataProvider')]
    public function testSingletonHttpHeader(string $headers, string $expectedHeaderName, $expectedHeaderValue,
        string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest('GET', '', $headers, '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        $data = $this->getDecodedBody($response);
        $headers = $data['serverRequest']['headers'];
        $this->assertArrayHasKey($expectedHeaderName, $headers, $failureMessage);
        $this->assertSame($expectedHeaderValue, $headers[$expectedHeaderName][0], $failureMessage);
    }

    /**
     * @see https://developers.cloudflare.com/rules/transform/request-header-modification/reference/header-format/
     * @see https://community.f5.com/kb/security-insights/f5-nginx-http-request-header-rules-what%E2%80%99s-permitted-and-what%E2%80%99s-not/334564
     */
    public static function singletonHttpHeaderDataProvider(): array
    {
        $cases = [
            // vanilla
            ['Custom: hey', 'Custom', 'hey'],
            // making sure 0, null and false do not gt dropped/interpreted
            ['C: 0', 'C', '0'],
            ['Custom: null', 'Custom', 'null'],
            ['Custom: false', 'Custom', 'false'],
            // a header with the shortest possible purely numeric name
            ['0: 1', '0', '1'],
            // No OWS before value
            ['Custom:hey', 'Custom', 'hey'],
            // OWS around value
            ["Custom: \t \t hey\t \t \t", 'Custom', 'hey'],
            // casing of rebuilt header name, whitespace inside value
            ["custom: hey hey\they", 'Custom', "hey hey\they"],
            // no interpretation of quoted-string by default
            ['CuStOm: "hey hey"', 'Custom', '"hey hey"'],
            // rfc9110 "token" production - for value
            ['Custom: !#$%&\'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', 'Custom', '!#$%&\'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'],
            // the chars not allowed in rfc9110 "token" - for value
            ['Custom: (),/:;<=>?@[\\]{}', 'Custom', '(),/:;<=>?@[\\]{}'],
            // trying to sneak a : in a header name results in a different header name and value
            ['Cus:tom: hey', 'Cus', 'tom: hey'],

            // DIGIT / ALPHA / "-" - for name
            ['0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-: hey', '0123456789abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz-', 'hey'],

            // A single http header does _not_ get split into an array and whitespace normalized because of the unquoted
            // commas (this is not a test for HeaderParser functionality)
            ["Custom: hey , hey\t,\they", 'Custom', "hey , hey\t,\they"],
        ];

        // non-ascii chars in header value, aka. obs-text (note that these header values are not valid utf8)
        $obsText = '';
        for ($i = 128; $i < 256; $i++) {
            $obsText .= chr($i);
        }
        $cases[] = ['Custom: ' . $obsText, 'Custom', $obsText];

        // obs-fold, ie. continuing a header on the next line
        // NB: Nginx, as of 1.28.3 at least, does not allow it, whereas apache and frankenphp do...
        if ($_ENV['SERVER_TYPE'] !== 'nginx') {
            $cases[] = ["Custom: hey\r\n  you", 'Custom', 'hey you'];
            $cases[] = ["Custom: hey\r\n\tyou", 'Custom', 'hey you'];
        }

        return self::mergeCommonDataProviderOptions($cases);
    }

    /**
     * Test http headers which cause all (tested) servers to pass them on to PHP - duplicate header
     */
    #[DataProvider('duplicateHttpHeaderDataProvider')]
    public function testDuplicateHttpHeader(string $headers, string $expectedHeaderName, $expectedHeaderValue,
        string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest('GET', '', $headers, '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        $data = $this->getDecodedBody($response);
        $headers = $data['serverRequest']['headers'];
        $this->assertArrayHasKey($expectedHeaderName, $headers, $failureMessage);
        $this->assertSame($expectedHeaderValue, $headers[$expectedHeaderName][0], $failureMessage);
    }

    public static function duplicateHttpHeaderDataProvider(): array
    {
/// @todo... these tests are known to fail with nginx 1.18 (and possibly later versions < 1.24).
///          We should interrogate the server ($_SERVER['SERVER_SOFTWARE']) and build the list of tests accordingly
        $cases = [
            // vanilla
            ["Custom: hey\r\nCustom: there", 'Custom', 'hey, there'],
            // OWS
            ["Custom: 1 \r\nNotCustom: 2 \r\nCustom: 3 ", 'Custom', '1, 3'],
            // No OWS
            ["Custom:1\r\nNotCustom:2\r\nCustom:3", 'Custom', '1, 3'],
            // Quotes
            ["Custom: '1'\r\nNotCustom: '2'\r\nCustom: '3'", 'Custom', "'1', '3'"],
            ["Custom: \"1,\"\r\nNotCustom: \"2\"\r\nCustom: \"3,\"", 'Custom', '"1,", "3,"'],
            ["Custom: \"1\r\nNotCustom: \"2\r\nCustom: \"3", 'Custom', '"1, "3'],
            ["Custom: '1\r\nNotCustom: '2\r\nCustom: '3", 'Custom', "'1, '3"],
            ["Custom: '1\"\r\nNotCustom: '2\"\r\nCustom: '3\"", 'Custom', "'1\", '3\""],
        ];

        // LF without CR
        // NB: Apache, as of 2026/7/21 at least, does not allow it, whereas frankenphp and nginx do...
        if ($_ENV['SERVER_TYPE'] !== 'apache') {
            $cases[] = ["Custom: hey\nCustom: there", 'Custom', 'hey, there'];
        }

        // tabs as OWS surrounding header vale
        /// @todo... as of 2026/7/21, frankenphp and apache agree on it, whereas nginx does not strip the tabs from whitespace
        ///          See https://github.com/nginx/nginx/issues/1597 -> https://github.com/nginx/nginx/issues/187
        if ($_ENV['SERVER_TYPE'] !== 'nginx') {
            $cases[] = ["Custom: \t1\t\r\nCustom: 2 \r\nCustom: \t3\t", 'Custom', '1, 2, 3'];
        }

        // smuggle in stuff: send 2 malformed http headers making the app see one valid singleton header...
        $cases[] = ["Date: Tue\r\nDate: 15 Nov 1994 08:12:31 GMT", 'Date', 'Tue, 15 Nov 1994 08:12:31 GMT'];
        // note that Set-Cookie is a Response header, and as such it is not parsed by webservers
        $cases[] = ["Set-Cookie: lang=en-US; Expires=Wed\r\nSet-Cookie: 09 Jun 2021 10:18:14 GMT", 'Set-Cookie', 'lang=en-US; Expires=Wed, 09 Jun 2021 10:18:14 GMT'];

        // one more test case where different webservers behave differently :-(
        // Nginx recognizes Cookie and treats it specifically, Apache and FrankenPHP do not
        if ($_ENV['SERVER_TYPE'] === 'nginx') {
            $cases[] = ["Cookie: lang1=xx-YY; lang2=en-US\r\nCookie: lang3=fr-FR", 'Cookie', 'lang1=xx-YY; lang2=en-US; lang3=fr-FR'];
        } else {
            $cases[] = ["Cookie: lang1=xx-YY; lang2=en-US\r\nCookie: lang3=fr-FR", 'Cookie', 'lang1=xx-YY; lang2=en-US, lang3=fr-FR'];
        }
        $cases[] = ['Cookie: withquotes="xx-YY"', 'Cookie', 'withquotes="xx-YY"'];

        return self::mergeCommonDataProviderOptions($cases);
    }

    /**
     * Test how the webserver parses quirky cookie headers and passes them to php via $_COOKIE
     */
    #[DataProvider('cookieDataProvider')]
    public function testCookieHttpHeader(string $headers, $expectedCookiesValue,
        string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest('GET', '', $headers, '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        $data = $this->getDecodedBody($response);
        /// @todo is it useful to check fir differences between these two?
        //$phpCookies = $data['_COOKIE'];
        $cookies = $data['serverRequest']['cookieParams'];
        $this->assertSame($expectedCookiesValue, $cookies, $failureMessage);
    }

    public static function cookieDataProvider(): array
    {
        $cases[] = ["Cookie: valid=",  ['valid' => '']];
        $cases[] = ["Cookie: invalid",  ['invalid' => '']];
        $cases[] = ["Cookie: one= o n e ",  ['one' => ' o n e']];

        // these are quite weird...
        $cases[] = ["Cookie: one =one",  ['one_' => 'one']];
        $cases[] = ["Cookie: one =one",  ['one_' => 'one']];
        $cases[] = ["Cookie: o n e=one",  ['o_n_e' => 'one']];
        $cases[] = ["Cookie: o\tne=one",  ["o\tne" => 'one']];
/// @todo... report this as php bug? Also: implement our own cookie parsing!!!
        //$cases[] = ["Cookie: o\tn\te=one",  ['o\tn\te' => 'one']];
/// @todo... add test cases for non-ascii 'token' chars in cookie name

        $cases[] = ['Cookie: withquotes="withquotes"',  ['withquotes' => '"withquotes"']];
        $cases[] = ['Cookie: one=one; two=two',  ['one' => 'one', 'two' => 'two']];
        $cases[] = ['Cookie: one="one"; two=two',  ['one' => '"one"', 'two' => 'two']];
        $cases[] = ['Cookie: one="one"; two=; three=3',  ['one' => '"one"', 'two' => '', 'three' => '3']];
        $cases[] = ["Cookie: one=one; ;\t;; three=3",  ['one' => 'one', 'three' => '3']];
        // subsequent spaces are not trimmed from cookie values
        $cases[] = ['Cookie: one=one   ; two=two',  ['one' => 'one   ', 'two' => 'two']];
        // in theory, a single space char should be found after the ';'...
        $cases[] = ["Cookie: one=one; \t two=two",  ['one' => 'one', 'two' => 'two']];

        // the use of double quoted spans does not interfere with splitting around
        $cases[] = ['Cookie: one="one;three=three"; two=two',  ['one' => '"one', 'three' => 'three"', 'two' => 'two']];
        $cases[] = ['Cookie: one="one ; three=three"; two=two',  ['one' => '"one ', 'three' => 'three"', 'two' => 'two']];


        $cases[] = ["Cookie: invalid=has space",  ['invalid' => 'has space']];
        $cases[] = ['Cookie: invalid=has"dquote',  ['invalid' => 'has"dquote']];
        $cases[] = ['Cookie: invalid=has,comma',  ['invalid' => 'has,comma']];
        $cases[] = ['Cookie: invalid=has\\backslash',  ['invalid' => 'has\\backslash']];
        $cases[] = ['Cookie: invalid=has;semicolon',  ['invalid' => 'has', 'semicolon' => '']];

        // one more test case where different webservers behave differently :-(
        // Apache glues together 2 Cookie lines using ', ' (and then allow that as cookie value), Nginx and FrankenPHP do not
        if ($_ENV['SERVER_TYPE'] === 'apache') {
            $cases[] = ["Cookie: lang1=xx-YY; lang2=en-US\r\nCookie: lang3=fr-FR", ['lang1' => 'xx-YY', 'lang2' => 'en-US, lang3=fr-FR']];
        } else {
            $cases[] = ["Cookie: lang1=xx-YY; lang2=en-US\r\nCookie: lang3=fr-FR",  ['lang1' => 'xx-YY', 'lang2' => 'en-US','lang3' => 'fr-FR']];
        }

        // NB: _COOKIE is most likely set up by php, there could be no need to repeat the test over http versions and protocols
        return self::mergeCommonDataProviderOptions($cases);
    }

    /**
     * Test http headers which cause all (tested) servers to either drop them or return a 400 error
     */
    #[DataProvider('droppedHttpHeaderDataProvider')]
    public function testDroppedHttpHeader(string $headers, bool $allow404s = true, string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest('GET', '', $headers, '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        // Different webservers react differently to this test - some drop the header, some reject the request.
        // Allow the test data to specify if 404s should be acceptable
        if ($allow404s && preg_match('#^HTTP/1.(0|1) 400 #', $response)) {
            $this->assertEquals(1, 1);
            return;
        }
        $data = $this->getDecodedBody($response);
        $headers = $data['serverRequest']['headers'];
        $this->assertArrayHasKey('Host', $headers, $failureMessage);
        $this->assertCount(1, $headers, $failureMessage);
    }

    public static function droppedHttpHeaderDataProvider(): array
    {
        $cases = [
            ['Custom:', false],

            /// @todo figure out why this one does get dropped or refused by all servers (educated guess: to avoid confusion
            ///       with security-related headers with a dash in their name?)
            ['Cus_tom: hey', false],

            // (),/:;<=>?@[\\]{}
            ['Cus(tom: hey', true],
            ['Cus)tom: hey', true],
            ['Cus,tom: hey', true],
            ['Cus/tom: hey', true],
            // this one results not in a dropped header but in a different header name and value (tested above)
            //['Cus:tom: hey', true],
            ['Cus;tom: hey', true],
            ['Cus<tom: hey', true],
            ['Cus=tom: hey', true],
            ['Cus>tom: hey', true],
            ['Cus?tom: hey', true],
            ['Cus@tom: hey', true],
            ['Cus[tom: hey', true],
            ['Cus]tom: hey', true],
            ['Cus\\tom: hey', true],
            ['Cus{tom: hey', true],
            ['Cus}tom: hey', true],
        ];

        // NB: FrankenPHP, as of 2026/7/21 at least, _does_ allow these chars in header names !!
        if ($_ENV['SERVER_TYPE'] !== 'frankenphp') {
            $cases = $cases + [
                // !#$%&\'*+.^_`|~
                ['Cus!tom: hey', true],
                ['Cus#tom: hey', true],
                ['Cus$tom: hey', true],
                ['Cus%tom: hey', true],
                ['Cus&tom: hey', true],
                ['Cus\'tom: hey', true],
                ['Cus*tom: hey', true],
                ['Cus+tom: hey', true],
                ['Cus.tom: hey', true],
                ['Cus^tom: hey', true],
                ['Cus`tom: hey', true],
                ['Cus|tom: hey', true],
                ['Cus~tom: hey', true],
            ];
        }

        return self::mergeCommonDataProviderOptions($cases);
    }

    /**
     * Test http headers which cause all (tested) servers to return a 400 error
     */
    #[DataProvider('rejectedHttpHeaderDataProvider')]
    public function testRejectedHttpHeader(string $headers, string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest('GET', '', $headers, '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        $this->assertMatchesRegularExpression('#^HTTP/1.(0|1) 400 #', $response, $failureMessage);
    }

    public static function rejectedHttpHeaderDataProvider(): array
    {
        $cases = [
            [':'],
            ['Custom'],
            // whitespace in header name
            ['Cus tom : hey'],
            ['Custom : hey'],
            [' Custom: hey'],
            ["Custom\t: hey"],
            ["\tCustom: hey"],
            // non-ascii char in header name
            ["Cüstom: hey"],
            // ctrl chars in header name
            ["Custom" . chr(0) . ": hey"],
            ["Custom" . chr(1) . ": hey"],
            ["Custom" . chr(2) . ": hey"],
            ["Custom" . chr(3) . ": hey"],
            ["Custom" . chr(4) . ": hey"],
            ["Custom" . chr(5) . ": hey"],
            ["Custom" . chr(6) . ": hey"],
            ["Custom" . chr(7) . ": hey"],
            ["Custom" . chr(8) . ": hey"],
            ["Custom" . chr(11) . ": hey"],
            ["Custom" . chr(12) . ": hey"],
            ["Custom" . chr(14) . ": hey"],
            ["Custom" . chr(15) . ": hey"],
            ["Custom" . chr(16) . ": hey"],
            ["Custom" . chr(17) . ": hey"],
            ["Custom" . chr(18) . ": hey"],
            ["Custom" . chr(19) . ": hey"],
            ["Custom" . chr(20) . ": hey"],
            ["Custom" . chr(21) . ": hey"],
            ["Custom" . chr(22) . ": hey"],
            ["Custom" . chr(23) . ": hey"],
            ["Custom" . chr(24) . ": hey"],
            ["Custom" . chr(25) . ": hey"],
            ["Custom" . chr(26) . ": hey"],
            ["Custom" . chr(27) . ": hey"],
            ["Custom" . chr(28) . ": hey"],
            ["Custom" . chr(29) . ": hey"],
            ["Custom" . chr(30) . ": hey"],
            ["Custom" . chr(31) . ": hey"],
            ["Custom" . chr(127) . ": hey"], // DEL
            // ctrl chars in header value
            ["Custom: " . chr(0)],
            ["Custom: " . chr(1)],
            ["Custom: " . chr(2)],
            ["Custom: " . chr(3)],
            ["Custom: " . chr(4)],
            ["Custom: " . chr(5)],
            ["Custom: " . chr(6)],
            ["Custom: " . chr(7)],
            ["Custom: " . chr(8)],
            ["Custom: " . chr(11)],
            ["Custom: " . chr(12)],
            ["Custom: " . chr(14)],
            ["Custom: " . chr(15)],
            ["Custom: " . chr(16)],
            ["Custom: " . chr(17)],
            ["Custom: " . chr(18)],
            ["Custom: " . chr(19)],
            ["Custom: " . chr(20)],
            ["Custom: " . chr(21)],
            ["Custom: " . chr(22)],
            ["Custom: " . chr(23)],
            ["Custom: " . chr(24)],
            ["Custom: " . chr(25)],
            ["Custom: " . chr(26)],
            ["Custom: " . chr(27)],
            ["Custom: " . chr(28)],
            ["Custom: " . chr(29)],
            ["Custom: " . chr(30)],
            ["Custom: " . chr(31)],
            ["Custom: " . chr(127)], // DEL

            /// @todo are there more known _always unsupported_ chars (ie. triggering a 4xx/5xx) in header name, header value?
            ///       we should probably add single \r and \n
        ];

        return self::mergeCommonDataProviderOptions($cases);
    }

    /**
     * Test CRLF at start of request.
     * Another fun discovery: no server respects the following suggestion from the rfc:
     * "In the interest of robustness, a server that is expecting to receive and parse a request-line SHOULD ignore at
     * least one empty line (CRLF) received prior to the request-line"
     */
    #[DataProvider('requestPrefixDataProvider')]
    public function testRequestPrefix(string $prefix, string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest($prefix . 'GET', '', '', '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        //$this->assertMatchesRegularExpression('#^HTTP/1.(0|1) 200 #', $response, $failureMessage);
        $this->assertMatchesRegularExpression('#^HTTP/1.(0|1) 400 #', $response, $failureMessage);
    }

    public static function requestPrefixDataProvider(): array
    {
        $cases = [
            ["\r\n"],
            ["\r\n\r\n"],
        ];

        return self::mergeCommonDataProviderOptions($cases);
    }

    /**
     * Test if non-standard HTTP methods reach php (hint: they shouldn't)
     */
    #[DataProvider('funkyHttpMethodsDataProvider')]
    public function testFunkyHttpMethodsPrefix(string $method, string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest($method, '', '', '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        $this->assertMatchesRegularExpression('#^HTTP/1.(0|1) 400 #', $response, $failureMessage);
    }

    public static function funkyHttpMethodsDataProvider(): array
    {
        $cases = [
            ["WHAT"],
        ];

        return self::mergeCommonDataProviderOptions($cases);
    }

    #[DataProvider('queryStringParametersDataProvider')]
    public function testQueryStringParameters(string $uriSuffix, array $expectedParameters, string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest('GET', $uriSuffix, '', '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        $data = $this->getDecodedBody($response);
        /// @todo is it useful to check fir differences between these two?
        //$phpParameters = $data['_GET'];
        $parameters = $data['serverRequest']['queryParams'];
        $this->assertSame($expectedParameters, $parameters, $failureMessage);
        //$this->assertMatchesRegularExpression('#^HTTP/1.(0|1) 400 #', $response, $failureMessage);
    }

    public static function queryStringParametersDataProvider(): array
    {
        $cases = [
            ["?a=hello", ['a' => 'hello']],
            ["?a=%20Hello+world%20", ['a' => ' Hello world ']],
            ["?a=+hello%20World+", ['a' => ' hello World ']],
            ["?a=", ['a' => '']],
            ["?a=1", ['a' => '1']],
            ["?a=-1", ['a' => '-1']],
            ["?a=1.0", ['a' => '1.0']],
            ["?a=true", ['a' => 'true']],
            ["?a=false", ['a' => 'false']],
            ["?a[]=", ['a' => ['']]],
            ["?a[2]=&a[1]=", ['a' => [2 => '', 1 => '']]],

            /// @todo... test: funky chars in param name

            /// @todo... fix 045_query_string_all after we fix this
        ];

        return self::mergeCommonDataProviderOptions($cases);
    }

    /**
     * @param array[] $cases
     * @return array[]
     */
    public static function mergeCommonDataProviderOptions(array $cases): array
    {
        $out = [];
        foreach ($cases as $line) {
            foreach (self::getCommonDataProviderOptions() as $options) {
                $out[] = $line + $options;
            }
        }
        return $out;
    }

    public static function getCommonDataProviderOptions(): array
    {
        $out = [];
        foreach (self::getSupportedServerSchemes() as $serverScheme) {
            foreach (['1.0', '1.1'] as $protocolVersion) {
                $out[] = [$protocolVersion, $serverScheme];
            }
        }
        return $out;
    }

    protected function customRequest(string $method = 'GET', string $urlSuffix = '', string $headers = '', string $body = '',
        string $httpVersion = '1.0', string $serverScheme = 'http'): string
    {
        $baseUri = $this->getServerBaseUri();
        $targetAddress = $this->getServerAddress();

        $payload = "$method " . $this->getServerPath() . $urlSuffix . " HTTP/$httpVersion\r\n";

        $payload .= 'Host: ' . preg_replace('#^https?://#', '', $baseUri) . "\r\n";

/// @todo... inject a cookie header with values for PHPUNIT_RANDOM_TEST_ID, PHPUNIT_SELENIUM_TEST_ID
        $headers = rtrim($headers, "\r\n");
        if ($headers !== '') {
            $payload .= $headers . "\r\n";
        }
        if ($httpVersion === '1.1') {
            // avoid timeouts
            $payload .= "Connection: close\r\n";
        }

        $payload .= "\r\n" . $body;

        $client = $this->getSimpleClient([], ['server_scheme' => $serverScheme]);
        return $client->sendPayload($targetAddress, $payload);
    }

    protected function getServerAddress(): string
    {
        /// @todo resolve other hostnames besides localhost
        $targetAddress = str_replace('://localhost', '://127.0.0.1', $this->getServerBaseUri());
        if (str_starts_with($targetAddress, 'http://')) {
            $targetAddress = preg_replace('#^http://#', 'tcp://', $targetAddress);
            if (!preg_match('#:[0-9]+$#', $targetAddress)) {
                $targetAddress .= ':80';
            }
        } elseif(str_starts_with($targetAddress, 'https://')) {
            $targetAddress = preg_replace('#^https://#', 'tls://', $targetAddress);
            if (!preg_match('#:[0-9]+$#', $targetAddress)) {
                $targetAddress .= ':443';
            }
        } else {
            throw new \RuntimeException("Unsupported target address protocol: $targetAddress");
        }
        return $targetAddress;
    }

    // the API of this function is less than  ideal, but it tries to be compatible with parent::getClient
    protected function getSimpleClient(array $clientOptions = [], array $testOptions = []): SimpleHttpClient
    {
        if (@$testOptions['server_scheme'] === 'unix') {
            $clientOptions['bindto'] = $_ENV['HTTPSERVER_SOCKET'];
        }

        // avoid tests lasting too long in case of things going south - the test server is supposed to respond quickly in any case
        $clientOptions = $clientOptions + [
            'timeout' => 2, // seconds
        ];

        return new SimpleHttpClient($clientOptions);
    }

    protected function getDecodedBody(string $response, $retCode = '200'): array
    {
        /// @todo "In the interest of robustness, a server that is expecting to receive and parse a request-line SHOULD
        ///       ignore at least one empty line (CRLF) received prior to the request-line" - not that any webserver
        ///       we are testing does support that...
        $this->assertMatchesRegularExpression('#^HTTP/1.(0|1) ' . preg_quote($retCode, '#') . ' #', $response);
        $body = $this->extractBody($response);
        $data = @json_decode($body, true);
        // support application/php-serialized+base64
        if (json_last_error() !== 0) {
            $data = @base64_decode($body);
            if ($data !== false) {
                $data = unserialize($data, ['allowed_classes' => false]);
            }
        }
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * Really simple separator of body from headers
     */
    protected function extractBody(string $response): string
    {
        /// @todo accept single \n as line terminators: "Although the line terminator for the start-line and fields is
        ///        the sequence CRLF, a recipient MAY recognize a single LF as a line terminator and ignore any preceding CR"
        $pos = strpos($response, "\r\n\r\n");
        if ($pos !== false) {
            return substr($response, $pos + 4);
        }
        return '';
    }

    protected function getRespDetails(string $response): string
    {
        return "Server response:\n$response\n";
    }
}
