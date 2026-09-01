<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use TanoWAF\WAFCore\Http\Dechunker;
use TanoWAF\WAFCore\Http\MessageParser;

/**
 * Tests the ServerRequestFactory class for all kind of weird http input.
 * In fact these tests are more of a smoke-test for the webserver used to run PHP, how it handles malformed http requests,
 * and what it lets through to the application.
 *
 * @todo... more tests: - anomalies in the start line
 *                      - headers which have a known syntax, to check if the webservers strip the double quotes and comments
 *                      - unexpected values for Host header (incl. double Host)
 */
class BA_ServerRequestFactoryTest extends ServerTestCase
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
        // NB: Nginx, as of 1.28.3 at least, and swoole 6.2.2 do not allow it, whereas apache and frankenphp do...
        if ($_ENV['SERVER_TYPE'] !== 'nginx' && $_ENV['SERVER_TYPE'] !== 'swoole') {
            $cases[] = ["Custom: hey\r\n  you", 'Custom', 'hey you'];
            $cases[] = ["Custom: hey\r\n\tyou", 'Custom', 'hey you'];
        }

        if ($_ENV['SERVER_TYPE'] === 'swoole') {
            // All servers but swoole do reject http headers with an underscore in their name (we test that below)
            $cases[] = ['Cus_tom: hey', 'Cus_Tom', 'hey'];
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
        $this->assertSame($expectedHeaderValue, is_string($expectedHeaderValue) ? $headers[$expectedHeaderName][0] : $headers[$expectedHeaderName], $failureMessage);
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
        if ($_ENV['SERVER_TYPE'] !== 'apache' && $_ENV['SERVER_TYPE'] !== 'swoole') {
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
        // Nginx and Swoole recognize Cookie and treats it specifically, Apache and FrankenPHP do not
        if ($_ENV['SERVER_TYPE'] === 'nginx') {
            $cases[] = ["Cookie: lang1=xx-YY; lang2=en-US\r\nCookie: lang3=fr-FR", 'Cookie', 'lang1=xx-YY; lang2=en-US; lang3=fr-FR'];
        } elseif ($_ENV['SERVER_TYPE'] === 'swoole') {
            $cases[] = ["Cookie: lang1=xx-YY; lang2=en-US\r\nCookie: lang3=fr-FR", 'Cookie', ['lang1=xx-YY; lang2=en-US', 'lang3=fr-FR']];
        } else {
            $cases[] = ["Cookie: lang1=xx-YY; lang2=en-US\r\nCookie: lang3=fr-FR", 'Cookie', 'lang1=xx-YY; lang2=en-US, lang3=fr-FR'];
        }
        $cases[] = ['Cookie: withquotes="xx-YY"', 'Cookie', 'withquotes="xx-YY"'];

        // swoole does in fact preserve multi-valued headers
        if ($_ENV['SERVER_TYPE'] === 'swoole') {
            foreach ($cases as $i => &$v) {
                if (is_string($v[2])) {
                    $v[2] = explode(', ', $v[2]);
                }
            }
        }

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
        /// @todo is it useful to check for differences between these two?
        //$phpCookies = $data['_COOKIE'];
        $cookies = $data['serverRequest']['cookieParams'];
        $this->assertSame($expectedCookiesValue, $cookies, $failureMessage);
    }

    public static function cookieDataProvider(): array
    {
        $cases[] = ["Cookie: valid=", ['valid' => '']];
        $cases[] = ["Cookie: invalid", ['invalid' => '']];
        $cases[] = ["Cookie: one= o n e ", ['one' => 'o n e']]; // $_COOKIE has: 'one' => ' o n e'

        // these are quite weird...
        $cases[] = ["Cookie: one =one", ['one' => 'one']]; // $_COOKIE has: 'one_' => 'one'
        $cases[] = ["Cookie: one.=one", ['one.' => 'one']]; // $_COOKIE has: 'one_' => 'one'
        $cases[] = ["Cookie: o n e=one", ['o n e' => 'one']]; // $_COOKIE has: 'o_n_e' => 'one'
        $cases[] = ["Cookie: o\tne=one", ["o\tne" => 'one']];

        /// @todo... report this as php bug?
        $cases[] = ["Cookie: o\tn\te=one",  ["o\tn\te" => 'one']];  // $_COOKIE has it different: ...

/// @todo... add test cases for non-ascii 'token' chars in cookie name

        $cases[] = ['Cookie: withquotes="withquotes"', ['withquotes' => 'withquotes']]; // $_COOKIE has: 'withquotes' => '"withquotes"'
        $cases[] = ['Cookie: one=one; two=two', ['one' => 'one', 'two' => 'two']];
        $cases[] = ['Cookie: one="one"; two=two', ['one' => 'one', 'two' => 'two']]; // $_COOKIE has: 'one' => '"one"', 'two' => 'two'
        $cases[] = ['Cookie: one="one"; two=; three=3', ['one' => 'one', 'two' => '', 'three' => '3']]; // $_COOKIE has: 'one' => '"one"', 'two' => '', 'three' => '3'
        $cases[] = ["Cookie: one=one; ;\t;; three=3", ['one' => 'one', 'three' => '3']];
        // subsequent spaces are not trimmed from cookie values
        $cases[] = ['Cookie: one=one   ; two=two', ['one' => 'one', 'two' => 'two']]; // $_COOKIE has: 'one' => 'one   ', 'two' => 'two'
        // in theory, a single space char should be found after the ';'...
        $cases[] = ["Cookie: one=one; \t two=two", ['one' => 'one', 'two' => 'two']];

        // the use of double-quoted spans does not interfere with splitting around
        $cases[] = ['Cookie: one="one;three=three"; two=two', ['one' => '"one', 'three' => 'three"', 'two' => 'two']];
        $cases[] = ['Cookie: one="one ; three=three"; two=two', ['one' => '"one', 'three' => 'three"', 'two' => 'two']];  // $_COOKIE has: 'one' => '"one ', 'three' => 'three"', 'two' => 'two'

        $cases[] = ["Cookie: invalid=has space", ['invalid' => 'has space']];
        $cases[] = ['Cookie: invalid=has"dquote', ['invalid' => 'has"dquote']];
        $cases[] = ['Cookie: invalid=has,comma', ['invalid' => 'has,comma']];
        $cases[] = ['Cookie: invalid=has\\backslash', ['invalid' => 'has\\backslash']];
        $cases[] = ['Cookie: invalid=has;semicolon', ['invalid' => 'has', 'semicolon' => '']];

        $cases[] = ['Cookie: one=one; one=two', ['one' => ['one', 'two']]]; // $_COOKIE has: 'one' => 'one'

        // one more test case where different webservers behave differently :-(
        // Apache and FP glue together 2 `Cookie` header lines using ', ' (and then use that as cookie value), Nginx
        // and our Swoole adapter do that in a smarter way using ';' (though not necessarily more rfc-compliant)
        /// @todo check: did this behaviour change in frankenphp 1.12.7 ??
        if ($_ENV['SERVER_TYPE'] === 'apache' || $_ENV['SERVER_TYPE'] === 'frankenphp') {
            $cases[] = ["Cookie: lang1=xx-YY; lang2=en-US\r\nCookie: lang3=fr-FR", ['lang1' => 'xx-YY', 'lang2' => 'en-US, lang3=fr-FR']];
        } else {
            $cases[] = ["Cookie: lang1=xx-YY; lang2=en-US\r\nCookie: lang3=fr-FR",  ['lang1' => 'xx-YY', 'lang2' => 'en-US', 'lang3' => 'fr-FR']];
        }

        // NB: _COOKIE is most likely set up by php, there could be no need to repeat the test over http versions and protocols
        return self::mergeCommonDataProviderOptions($cases);
    }

    /**
     * Test http headers which cause all (tested) servers to either drop them or return a 400 error
     */
    #[DataProvider('droppedHttpHeaderDataProvider')]
    public function testDroppedHttpHeader(string $headers, bool $expect400s = false, string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest('GET', '', $headers, '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        // Different webservers react differently to this test - some drop the header, some reject the request.
        // Allow the test data to specify if 400s should be expected
        if ($expect400s) {
            $this->assertStatusCode(400, $response, $failureMessage);
            return;
        }
        $data = $this->getDecodedBody($response);
        $headers = $data['serverRequest']['headers'];
        $this->assertArrayHasKey('Host', $headers, $failureMessage);
        $this->assertCount(1, $headers, $failureMessage);
    }

    public static function droppedHttpHeaderDataProvider(): array
    {
        $expect400 = ($_ENV['SERVER_TYPE'] !== 'nginx');

        //$cases[] = [];
        $cases = [
            ['Custom:', false],

            // (),/:;<=>?@[\\]{}
            ['Cus(tom: hey', $expect400],
            ['Cus)tom: hey', $expect400],
            ['Cus,tom: hey', $expect400],
            ['Cus/tom: hey', $expect400],
            // this one results not in a dropped header but in a different header name and value (tested above)
            //['Cus:tom: hey', $expect400],
            ['Cus;tom: hey', $expect400],
            ['Cus<tom: hey', $expect400],
            ['Cus=tom: hey', $expect400],
            ['Cus>tom: hey', $expect400],
            ['Cus?tom: hey', $expect400],
            ['Cus@tom: hey', $expect400],
            ['Cus[tom: hey', $expect400],
            ['Cus]tom: hey', $expect400],
            ['Cus\\tom: hey', $expect400],
            ['Cus{tom: hey', $expect400],
            ['Cus}tom: hey', $expect400],
        ];

        if ($_ENV['SERVER_TYPE'] !== 'swoole') {
            /// @todo figure out why this one does get dropped or refused by all servers but Swoole (educated guess:
            ///       to avoid confusion with security-related headers with a dash in their name?)
            $cases[] = ['Cus_tom: hey', false];
        }

        // NB: FrankenPHP, as of 2026/7/21 at least, _does_ allow these chars in header names !!
        if ($_ENV['SERVER_TYPE'] !== 'frankenphp') {
            $cases = $cases + [
                // !#$%&\'*+.^_`|~
                ['Cus!tom: hey', $expect400],
                ['Cus#tom: hey', $expect400],
                ['Cus$tom: hey', $expect400],
                ['Cus%tom: hey', $expect400],
                ['Cus&tom: hey', $expect400],
                ['Cus\'tom: hey', $expect400],
                ['Cus*tom: hey', $expect400],
                ['Cus+tom: hey', $expect400],
                ['Cus.tom: hey', $expect400],
                ['Cus^tom: hey', $expect400],
                ['Cus`tom: hey', $expect400],
                ['Cus|tom: hey', $expect400],
                ['Cus~tom: hey', $expect400],
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
        $this->assertStatusCode(400, $response, $failureMessage);
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
     * Test http headers which cause all (tested) servers to return a 400 error
     */
    #[DataProvider('bigHttpHeaderDataProvider')]
    public function testBigHttpHeader(string $headers, string $httpVersion = '1.0', string $serverScheme = 'http'): void
    {
        $response = $this->customRequest('GET', '', $headers, '', $httpVersion, $serverScheme);
        $failureMessage = $this->getRespDetails($response);
        /// @todo check which servers send back a 413 and which ones a 40x
        $this->assertStatusCode([400, 413], $response, $failureMessage);
    }

    public static function bigHttpHeaderDataProvider(): array
    {
        // header length over any reasonable buffer (100K)
        $cases = [
            ['Custom: '. str_repeat('1234567890', 10000)],
            ['Cookie: x='. str_repeat('1234567890', 10000)]
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
        $this->assertStatusCode(400, $response, $failureMessage);
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
        $this->assertStatusCode(400, $response, $failureMessage);
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
        /// @todo is it useful to check for differences between these two?
        //$phpParameters = $data['_GET'];
        $parameters = $data['serverRequest']['queryParams'];
        $this->assertSame($expectedParameters, $parameters, $failureMessage);
    }

    public static function queryStringParametersDataProvider(): array
    {
        $cases = [
            // "non-controversial" cases

            ["?a=hello", ['a' => 'hello']],
            ["?a=!$'(),-./:;@_~", ['a' => "!$'(),-./:;@_~"]], // no + sign as that's used for escaping
            ["?a=%20Hello+world%20", ['a' => ' Hello world ']],
            ["?a=+hello%20World+", ['a' => ' hello World ']],
            ["?a", ['a' => '']],
            ["?a=", ['a' => '']],
            ["?a=000000", ['a' => '000000']],
            ["?a=1", ['a' => '1']],
            ["?a=-1", ['a' => '-1']],
            ["?a=1.0", ['a' => '1.0']],
            ["?a=1...0", ['a' => '1...0']],
            ["?a=1,0", ['a' => '1,0']],
            ["?a=true", ['a' => 'true']],
            ["?a=false", ['a' => 'false']],
            ["?a=%7e", ['a' => '~']],
            ["?a=%7E", ['a' => '~']],

            // @see https://url.spec.whatwg.org/#url-code-points
            // "The URL code points are ASCII alphanumeric, U+0021 (!), U+0024 ($), U+0026 (&), U+0027 ('), U+0028 LEFT PARENTHESIS, U+0029 RIGHT PARENTHESIS, U+002A (*), U+002B (+), U+002C (,), U+002D (-), U+002E (.), U+002F (/), U+003A (:), U+003B (;), U+003D (=), U+003F (?), U+0040 (@), U+005F (_), U+007E (~), and code points in the range U+00A0 to U+10FFFD, inclusive, excluding surrogates and noncharacters"
            //["?a_=y", ['a_' => 'y']],
            ["?a!=y", ['a!' => 'y']],
            ["?a$=y", ['a$' => 'y']],
            ["?a'=y", ["a'" => 'y']],
            ["?a(=y", ['a(' => 'y']],
            ["?a)=y", ['a)' => 'y']],
            ["?a,=y", ['a,' => 'y']],
            ["?a-=y", ['a-' => 'y']],
            ["?a/=y", ['a/' => 'y']],
            ["?a:=y", ['a:' => 'y']],
            ["?a;=y", ['a;' => 'y']],
            ["?a@=y", ['a@' => 'y']],
            ["?a_=y", ['a_' => 'y']],
            ["?a~=y", ['a~' => 'y']],
            ["?%20a%7e=y", ['a~' => 'y']],
            ["?a%7E=y", ['a~' => 'y']],

            // "slightly-controversial" cases

            /// @todo repeated params, array-params are not part of the url spec. There are different ways of parsing them...
            ["?a=false&a=true", ['a' => 'true']],
            ["?a[]=", ['a' => ['']]],
            ["?a[2]=&a[1]=", ['a' => [2 => '', 1 => '']]],
            ["?a[2]=y&a[2]=n", ['a' => [2 => 'n']]],

            //["?a+=y", ['a ' => 'y']], /// @todo... atm it results in 'a_'. Remember to fix 045_query_string_all after we fix this
            //["?a.=y", ['a.' => 'y']], /// @todo... atm it results in 'a_'
            //["?%20%20a%20a%20%20=y", ['  a a  ' => 'y']], /// @todo... atm it results in 'a_a__'
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
        $uri = $this->getServerPath() . $urlSuffix;
        $host = preg_replace('#^https?://#', '', $baseUri);

        $payload = "$method $uri HTTP/$httpVersion\r\n" .
            "Host: $host\r\n";

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
        $response = $client->sendPayload($targetAddress, $payload);

        $this->assertNotEquals('', $response, "Empty http reply on $method request to $uri (host: $host)");

        return $response;
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

    protected function getDecodedBody(string $response, int|string|null $retCode = '200'): array
    {
        if ($retCode != '') {
            $this->assertStatusCode($retCode, $response);
        }
        $body = $this->extractBody($response);
        $data = @json_decode($body, true);
        // support application/php-serialized+base64
        if (json_last_error() !== 0) {
            $data = @base64_decode($body);
            if ($data !== false) {
                $data = @unserialize($data, ['allowed_classes' => false]);
            }
        }
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * Really simple separator of body from headers. Supports chunked transfer encoding
     * @todo move this functionality into the MessageParser
     */
    protected function extractBody(string $response, bool $dechunk = true): string
    {
        $messageParser = new MessageParser();
        list ($startLine, $headers, $body) = $messageParser->splitMessage($response);
        if ($body !== '' && $dechunk) {
            foreach ($headers as $header) {
                if (str_starts_with(strtolower($header), 'transfer-encoding:') && trim(substr($header, 18), " \t") == 'chunked') {
                    $dechunker = new Dechunker();
                    $body = $dechunker->dechunk($body);
                    break;
                }
            }
        }
        return $body;
    }

    protected function extractStartLine(string $response): string
    {
        $messageParser = new MessageParser();
        list ($startLine, $headers, $body) = $messageParser->splitMessage($response);
        return $startLine;
    }

    protected function getRespDetails(string $response): string
    {
        return "Server response:\n$response\n";
    }

    protected function assertStatusCode(int|string|array $retCode, string $response, string $message = ''): void
    {
        if (is_array($retCode)) {
            $retCode = array_map(function ($v) {return preg_quote((string)$v, '#');}, $retCode);
            $retCode = '(' . implode('|', $retCode) . ')';
        } else {
            $retCode = preg_quote((string)$retCode, '#');
        }
        $this->assertMatchesRegularExpression(
            '#^HTTP/1\\.(0|1) ' . $retCode . ' #',
            $this->extractStartLine($response),
            $message
        );
    }
}
