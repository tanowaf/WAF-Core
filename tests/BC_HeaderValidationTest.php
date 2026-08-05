<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use TanoWAF\WAFCore\Http\HeaderFormat;
use TanoWAF\WAFCore\Http\HeaderParser;
use TanoWAF\WAFCore\Http\HeaderQuotedSpansFormat;
use TanoWAF\WAFCore\Http\HeaderSpec;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @todo we should move the tested header values to an external file, such as csv, so that it can be easily reused
 *       by other frameworks (or vice-versa, could we reuse the test cases of other sdks)?
 *       Note that it should be a format making it easy both to spot the presence of chars such as "\t" and to avoid
 *       having to escape too many chars, for when eg. the header is a json string. This makes both csv and json kind
 *       of suboptimal... :-(
 */
class BC_HeaderValidationTest extends TestCase
{
    #[DataProvider('compliantHeadersDataProvider')]
    public function testCompliantHeaders(array $values, string $format)
    {
        $hp = new HeaderParser(['Custom' => new HeaderSpec(HeaderFormat::from($format))]);
        $ok = $hp->validateHeaderValue('Custom', $values, $errors);
        $this->assertTrue($ok, implode(', ', $errors));
    }

    public static function compliantHeadersDataProvider()
    {
        return [
            [['hello,world'], 'generic'],
            [['hello,world','again'], 'generic'],

            [['hello=world'], 'cookie'],
            [['hello="world"'], 'cookie'],
            [['hello=world; world=hello'], 'cookie'],
            [['hello=world; world="hello"'], 'cookie'],
            [['0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ!#$%&\'*+-.^_`|~abcdefghijklmnopqrstuvwxyz=world'], 'cookie'],
            [['hello=!#$%&\'*+-.^_`|~'], 'cookie'],

            [['Sun, 06 Nov 1994 08:49:37 GMT'], 'date'],
            [['Sunday, 06-Nov-94 08:49:37 GMT'], 'date'],
            [['Sun Nov 16 08:49:37 1994'], 'date'],
            [['Sun Nov  6 08:49:37 1994'], 'date'],

            [['1'], 'integer'],

            [['{ "hello" : "world", "array":[true, false, null, 1, -0.2e-1, [], {}, ["nested"]]}'], 'json'],

            [['hello,world'], 'token'],
            [['hello,world','again'], 'token'],

            // Integer
            [['-1'], 'Item'],
            [['1'], 'Item'],
            [['1234567890'], 'IntegerItem'],
            [['1234567890.0123456789'], 'Item'],
            [['-1234567890.0123456789'], 'DecimalItem'],
            // String
            [['"hello;, world"'], 'Item'],
            [['"hello,; world"'], 'StringItem'],
            // Base64
            [[':' . base64_encode('hello') . ':'], 'Item'],
            [[':' . base64_encode('') . ':'], 'ByteSequenceItem'],
            // Bool
            [['?0'], 'Item'],
            [['?1'], 'BooleanItem'],
            // Date
            [['@1'], 'Item'],
            [['@123456789'], 'DateItem'],
            // DisplayString
            [['%""'], 'Item'],
            [['%"Hello"'], 'DisplayStringItem'],
            [['%"Hello%20"'], 'Item'],
            [['%"Hello%ff"'], 'Item'],
            [['%"%20Hello"'], 'Item'],
            [['%"%ffHello"'], 'Item'],
            // Token
            [['hello'], 'Item'],
            [['*'], 'TokenItem'],
            [['hello', 'world'], 'Item'], /// @todo arguable! According to the spec, it could fail
            // Parameters
            [['hello;p0'], 'Item'],
            [['hello;p0;p1'], 'Item'],
            [['hello;p0;p1=?0'], 'Item'],
            [['hello;p0;p1=?0;p2=?1'], 'Item'],
            [['hello;p0;p1=?0;p2=?1;p3=-1'], 'Item'],
            [['hello;p0;p1=?0;p2=?1;p3=-1;p4=1'], 'Item'],
            [['hello;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0'], 'Item'],
            [['hello;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=0.1;p6="hello"'], 'Item'],
            [['hello;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world"'], 'Item'],
            [['hello;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=0.1;p6="hello;, world";p7=@1'], 'Item'],
            [['hello;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@12;p8=%"world"'], 'Item'],
            [['hello;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=0.1;p6="hello;, world";p7=@123;p8=%"world";p9=hello'], 'Item'],
            [['-1;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1234;p8=%"world";p9=hello;p10=*'], 'Item'],
            [['"hello;, world";p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1234;p8=%"world";p9=hello;p10=*'], 'Item'],
            [['::;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1234;p8=%"world";p9=hello;p10=*'], 'Item'],
            [['?0;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1234;p8=%"world";p9=hello;p10=*'], 'Item'],
            [['@0;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1234;p8=%"world";p9=hello;p10=*'], 'Item'],
            [['%"";p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1234;p8=%"world";p9=hello;p10=*'], 'Item'],
            // List
            [['hello'], 'List'],
            [['hello,world'], 'List'],
            [['"World", :w4ZibGV0w6ZydGU=: ,@123456789 , -1234567890.0123456789, %"%ffHello"'], 'List'],
            [['"World";p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=*, :w4ZibGV0w6ZydGU=:;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=* ,@123456789;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=* , -1234567890.0123456789;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=*, %"%ffHello";p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=*'], 'List'],
            // Dictionary
            [['hello=world'], 'Dictionary'],
            [['hello'], 'Dictionary'],
            [['hello, world'], 'Dictionary'],
            [['hello=hello,hello=world'], 'Dictionary'],
            [['hello=world;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=*'], 'Dictionary'],
            [['hello="World", base64=:w4ZibGV0w6ZydGU=: ,date=@123456789 , *1234567890=-1234567890.0123456789, funny_-.*=%"%ffHello"'], 'Dictionary'],
            [['hello="World";p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=*, base64=:w4ZibGV0w6ZydGU=:;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=* ,date=@123456789;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=* , *1234567890=-1234567890.0123456789;p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=*, funny_-.*=%"%ffHello";p0;p1=?0;p2=?1;p3=-1;p4=1;p5=1.0;p6="hello;, world";p7=@1;p8=%"world";p9=hello;p10=*'], 'Dictionary'],
        ];
    }

    #[DataProvider('nonCompliantHeadersDataProvider')]
    public function testNonCompliantHeaders(array $values, string $format)
    {
        $hp = new HeaderParser(['Custom' => new HeaderSpec(HeaderFormat::from($format))]);
        $this->assertFalse($hp->validateHeaderValue('Custom', $values));
    }

    public static function nonCompliantHeadersDataProvider()
    {
        return [
            [['hello =world'], 'cookie'],
            [['hello= world'], 'cookie'],
            [['hello="world'], 'cookie'],
            [['hello=world"'], 'cookie'],
            [['hello=world;'], 'cookie'],
            [['hello=world ;yo=lo'], 'cookie'],
            [['hello=world ; yo=lo'], 'cookie'],
            [["hello=world;  yo=lo"], 'cookie'],
            [["hello=world;\tyo=lo"], 'cookie'],
            /// @todo att tests for chars not valid in either cookie name or value

            [['S.n, 06 Nov 1994 08:49:37 GMT'], 'date'],
            [['S.nday, 06-Nov-94 08:49:37 GMT'], 'date'],
            [['S.n Nov  6 08:49:37 1994'], 'date'],

            [['"hello,world"'], 'integer'],

            [['{"hello,world"'], 'json'],

            [['"hello,world"'], 'token'],

            [['hello, world'], 'Item'],
            [['1'], 'BooleanItem'],
            [['?0'], 'StringItem'],

/// @todo... this one should fail, but atm it does not!
            //[['hello;'], 'Item'],

            [['-'], 'Item'],
            [['-a'], 'Item'],

            [['"'], 'Item'],
            [['"a'], 'Item'],

            [[':'], 'Item'],
            [[':.:'], 'Item'],

            [['?'], 'Item'],
            [['?a'], 'Item'],

            [['@'], 'Item'],
            [['@a'], 'Item'],

            [['%'], 'Item'],
            [['%"'], 'Item'],
            [['%"a'], 'Item'],
            [['%"a%"'], 'Item'],
            [['%"a%ZZ"'], 'Item'],

            [['a"'], 'Item'],

            [['a;b;;'], 'Item'],
            [['a;b="'], 'Item'],
            [['a;b;c=@'], 'Item'],
            [['a;b=b;c=:'], 'Item'],
            [['a;b=?;c'], 'Item'],

/// @todo... these ones should fail, but atm they do not!
            //[['a,b,'], 'List'],
            [['a,b,,'], 'List'],
            [['a,,b'], 'List'],
            [[',a,b'], 'List'],

            //[['a=a,b=b,'], 'Dictionary'],
            [['a=a,b=b,,'], 'Dictionary'],
            [['a=a,,b=b'], 'Dictionary'],
            [[',a=a,b=b'], 'Dictionary'],
            //[['a,b,'], 'Dictionary'],
            [['a,b,,'], 'Dictionary'],
            [['a,,b'], 'Dictionary'],
            [[',a,b'], 'Dictionary'],
        ];
    }

    public function testSingletonHeaders()
    {
        $hp = new HeaderParser(['Custom' => new HeaderSpec(HeaderFormat::from('generic'), null, HeaderQuotedSpansFormat::None, true)]);
        $this->assertFalse($hp->validateHeaderValue('Custom', ['hello,world']));
        $this->assertFalse($hp->validateHeaderValue('Custom', ['"hello,world"']));
        $this->assertFalse($hp->validateHeaderValue('Custom', ['hello','world']));
        $this->assertFalse($hp->validateHeaderValue('Custom', ['"hello','world"']));

        $hp = new HeaderParser(['Custom' => new HeaderSpec(HeaderFormat::from('generic'), null, HeaderQuotedSpansFormat::QuotedString, true)]);
        $this->assertTrue($hp->validateHeaderValue('Custom', ['"hello,world"']));
        $this->assertTrue($hp->validateHeaderValue('Custom', ['hello","world']));

        $hp = new HeaderParser(['Custom' => new HeaderSpec(HeaderFormat::from('generic'), null, HeaderQuotedSpansFormat::QuotedString, true)]);
        $this->assertFalse($hp->validateHeaderValue('Custom', ['hello",world"','world']));
        $this->assertFalse($hp->validateHeaderValue('Custom', ['hello"\\,world"','world']));
    }
}
