<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use TanoWAF\WAFCore\Filter\Bidirectional\UnixCompressor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class AC_CompressSmokeTest extends TestCase
{
    /**
     * Tests round-trip compress/uncompress of random data
     */
    #[DataProvider('compressTestsDataProvider')]
    public function testCompressRoundtrip(string $data): void
    {
        $compressed = UnixCompressor::compress($data);
        $decompressed = UnixCompressor::uncompress($compressed);

        $this->assertSame($data, $decompressed);
    }

    /**
     * Tests round-trip compress/uncompress of random data vs. the native `compress` cli tool
     */
    #[DataProvider('compressTestsDataProvider')]
    public function testCompressVsNative(string $data): void
    {
        $fileName = sys_get_temp_dir() . '/' . md5($data);
        file_put_contents($fileName, $data);
        exec("compress -f -- " . escapeshellarg($fileName), $out, $retCode);

        if ($retCode !== 0 || !file_exists($fileName . '.Z')) {
            $this->markTestSkipped("Can not compare compress results: native cli tool failed");
        }

        $unixCompressed = file_get_contents($fileName . '.Z');

        if (file_exists($fileName)) {
            unlink($fileName);
        }
        if (file_exists($fileName . '.Z')) {
            unlink($fileName . '.Z');
        }

/// @todo... enable this, after we fix the trailing padding bytes issue
        //$compressed = UnixCompressor::compress($data);
        //$this->assertSame($unixCompressed, $compressed);

        $decompressed = UnixCompressor::uncompress($unixCompressed);
        $this->assertSame($data, $decompressed);
    }


    /**
     * Returns 10 random binary strings for each of lengths range: 1-10, 10-100, 100-1000, 1000-10000, plus ''
     * @return string[]
     */
    public static function compressTestsDataProvider(): array
    {
        $out = [['']];
        $minLen = 1;
        for ($i = 1; $i < 5; $i++) {
            $maxLen = 10 ** $i;
            $len = rand($minLen, $maxLen);
            for ($j = 0; $j < 10; $j++) {
                $string = '';
                for ($k = 1; $k <= $len; $k++) {
                    $string .= chr(rand(0, 255));
                }
                $out[] = [$string];
            }
            $minLen = $maxLen;
        }
        return $out;
    }
}
