<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use TanoWAF\WAFCore\Http\HeaderFormat;
use TanoWAF\WAFCore\Http\HeaderParser;
use TanoWAF\WAFCore\Http\HeaderQuotedSpansFormat;
use TanoWAF\WAFCore\Http\HeaderSpec;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class BB_HeaderParsingTest extends TestCase
{
    #[DataProvider('normalizingCustomHeadersDataProvider')]
    public function testNormalizingCustomHeaders(array $values, array $expectedResults, bool $expectErrors = false)
    {
        $hp = new HeaderParser(['Custom' => new HeaderSpec(HeaderFormat::Generic)]);
        $errors = [];
        $this->assertSame($expectedResults, $hp->normalizeHeaderValue('Custom', $values, $errors));
        if ($expectErrors) {
            $this->assertGreaterThanOrEqual(1, count($errors), "No errors found while normalizing header, but some were expected");
        } else {
            $this->assertCount(0, $errors, 'Unexpected errors while normalizing header: ' . implode("\n", $errors));
        }
    }

    // multi-valued, no double-quoted-strings header
    public static function normalizingCustomHeadersDataProvider()
    {
        return [
            [[], []],
            [[''], []],
            [['hello'], ['hello']],
            [[" \thello \t"], ['hello']],
            [['hello world'], ['hello world']],
            [["hello'world"], ["hello'world"]],

            [['hello,world'], ['hello', 'world']],
            [['hello , world'], ['hello', 'world']],
            [["hello  \t ,\t \tworld"], ['hello', 'world']],
            [["hello,, ,  ,\t,\t\tworld"], ['hello', 'world']],
            [[",hello, world"], ['hello', 'world']],
            [["hello, world,"], ['hello', 'world']],
            [[",hello, world,"], ['hello', 'world']],

            [['"'], ['"']],
            [['""'], ['""']],
            [['"""'], ['"""']],
            [[' " " "'], ['" " "']],
            [['hello"world'], ['hello"world']],
            [['hello world"'], ['hello world"']],
            [['"hello world'], ['"hello world']],
            [['"hello world"'], ['"hello world"']],
            [['"hello,world"'], ['"hello', 'world"']],
            [['"hello, world"'], ['"hello', 'world"']],

            [['hello', 'world'], ['hello', 'world']],
            [[',hello, ,', 'world'], ['hello', 'world']],
            [['hello', ', ,world,'], ['hello', 'world']],
            [['', 'hello,world', ''], ['hello', 'world']],
            [['hello,world', 'again'], ['hello', 'world', 'again']],
            [[',,hello,,world,,' ,'again'], ['hello', 'world', 'again']],
        ];
    }

    #[DataProvider('normalizingDQHeadersDataProvider')]
    public function testNormalizingDQHeaders($values, $expectedResults, bool $expectErrors = false)
    {
        $hp = new HeaderParser(['Custom' => new HeaderSpec(HeaderFormat::Generic, null, HeaderQuotedSpansFormat::QuotedString)]);
        $this->assertSame($expectedResults, $hp->normalizeHeaderValue('Custom', $values, $errors));
        if ($expectErrors) {
            $this->assertGreaterThanOrEqual(1, count($errors), "No errors found while normalizing header, but some were expected");
        } else {
            $this->assertCount(0, $errors, 'Unexpected errors while normalizing header: ' . implode("\n", $errors));
        }
    }

    // multi-valued, allows double-quoted-strings header
    public static function normalizingDQHeadersDataProvider()
    {
        return [
            [[], []],
            [[''], []],
            [['hello'], ['hello']],
            [[" \thello \t"], ['hello']],
            [['hello world'], ['hello world']],
            [["hello'world"], ["hello'world"]],

            [['hello,world'], ['hello', 'world']],
            [['hello , world'], ['hello', 'world']],
            [["hello  \t ,\t \tworld"], ['hello', 'world']],
            [["hello,, ,  ,\t,\t\tworld"], ['hello', 'world']],
            [[",hello, world"], ['hello', 'world']],
            [["hello, world,"], ['hello', 'world']],
            [[",hello, world,"], ['hello', 'world']],

            // NB: these 3 tests check corner-case situations for which there is no well-defined result.
            // Unlike the Structured Fields RFC, the main HTTP rfcs have no indication about error handling and
            // how to treat headers where the value does not satisfy the specification.
            // Given the and that the same matcher can be used both within an Allow and a Deny rule, it is also hard to
            // make a good choice regarding the default behaviour for when trying to match the content of a non-compliant
            // header. For this reason, a separate matcher has been developed, focused on finding non-compliant headers.
            [['hello"world'], ['helloworld'], true],
            [['hello world"'], ['hello world'], true], /// @todo... should we modify the parser to change this result?
            [['"hello world'], ['hello world'], true],

            [['""'], ['']],
            [['"\\""'], ['"']],
            [['"hello world"'], ['hello world']],
            [['"hello,world"'], ['hello,world']],
            [['"hello, world"'], ['hello, world']],
            [['"hello\\"world"'], ['hello"world']],
            [['"hello \\world"'], ['hello world']],
            [['"\\h\\e\\l\\l\\o \\w\\o\\r\\l\\d"'], ['hello world']],

            [['hello', 'world'], ['hello', 'world']],
            [[',hello, ,', 'world'], ['hello', 'world']],
            [['hello', ', ,world,'], ['hello', 'world']],
            [['', 'hello,world', ''], ['hello', 'world']],
            [['hello,world', 'again'], ['hello', 'world', 'again']],
            [[',,hello,,world,,', 'again'], ['hello', 'world', 'again']],

            [[' "hello world" '], ['hello world']],
            [['hello "hello,world" world'], ['hello hello,world world']],
            [[' hello  "hello,world"  world'], ['hello  hello,world  world']],
            [['hello "hello,world" world '], ['hello hello,world world']],
            [['hello " hello,world " world '], ['hello  hello,world  world']],
            [['" hello,world "'], [' hello,world ']],

            [['""', 'again'], ['', 'again']],
            [['"\\""', 'again'], ['"', 'again']],
            [['"hello world"', 'again'], ['hello world', 'again']],
            [['"hello,world"', 'again'], ['hello,world', 'again']],
            [['"hello, world"', 'again'], ['hello, world', 'again']],
            [['"hello\\"world"', 'again'], ['hello"world', 'again']],
            [['"hello \\world"', 'again'], ['hello world', 'again']],
            [['"\\h\\e\\l\\l\\o \\w\\o\\r\\l\\d"', 'again'], ['hello world', 'again']],

            [['hello, "hello, world", world'], ['hello', 'hello, world', 'world']],

            /// @todo any more DQ strings to test?
        ];
    }

    #[DataProvider('normalizingJsonHeadersDataProvider')]
    public function testNormalizingJsonHeaders(array $values, array $expectedResults, bool $expectErrors = false)
    {
        $hp = new HeaderParser(['Custom' => new HeaderSpec(HeaderFormat::Json)]);
        $errors = [];
        $this->assertSame($expectedResults, $hp->normalizeHeaderValue('Custom', $values, $errors));
        if ($expectErrors) {
            $this->assertGreaterThanOrEqual(1, count($errors), "No errors found while normalizing header, but some were expected");
        } else {
            $this->assertCount(0, $errors, 'Unexpected errors while normalizing header: ' . implode("\n", $errors));
        }
    }

    public static function normalizingJsonHeadersDataProvider()
    {
        return [
            [['{" hello ":" world "}'], ['{" hello ":" world "}']],
            [['{ "hello" : "world" }'], ['{"hello":"world"}']],
            [["{\t\"hello\"\t:\t\"world\"\t}"], ['{"hello":"world"}']],
            [['{"hello": "world"}'], ['{"hello":"world"}']],
            [['{"hello": true}'], ['{"hello":true}']],
            [['{"hello": false}'], ['{"hello":false}']],
            [['{"hello": null}'], ['{"hello":null}']],
            [['{"hello": 1}'], ['{"hello":1}']],
            [['{"hello": 1.1}'], ['{"hello":1.1}']],
            [['{"hello": "\u0020"}'], ['{"hello":" "}']],
            [['{"hello": {"hello": {"hello": null}}}'], ['{"hello":{"hello":{"hello":null}}}']],
            [['{"hello": {"hello": {"hello": null}}}'], ['{"hello":{"hello":{"hello":null}}}']],

            [['{"hello":["world"]}'], ['{"hello":["world"]}']],
            [['{"hello":["world" , 1, "again"]}'], ['{"hello":["world",1,"again"]}']],
            [['{"hello":["world" , 1, "again"]}'], ['{"hello":["world",1,"again"]}']],

            [['{}'], ['{}']],
            [['[]'], ['[]']],
            [['{"0" : "0" , "1" : "1"}'], ['{"0":"0","1":"1"}']],

            [['1'], ['1']],

            /// @todo this test fails!
            //[['1.0'], ['1.0']],
            /// @todo the following 2 cases results in different spacing of the produced output :-(
            //[['{"hello": "\\\" \\/ \\\\"}'], ['{"hello":"\\\" \\/ \\\\"}']],
            //[['{"hello": [ {"hello": {"hello": null}}}, 1, true]'], ['{"hello":[{"hello":{"hello":null}}},1,true]']],
        ];
    }

/// @todo... tests for more cases:
///          singleton, no double-quotes
///          singleton, double-quotes
///          structured items
///          cookies

}
