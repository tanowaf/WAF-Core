<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use TanoWAF\WAFCore\Logger\FileLogger;
use TanoWAF\WAFCore\Logger\JsonFileLogger;
use TanoWAF\WAFCore\Logger\LoggerChain;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests Loggers, without going through the waf.
 */
class AD_LoggerSmokeTest extends TestCase
{
    public function testFileLoggers()
    {
        if (file_exists(sys_get_temp_dir() . '/test.log')) {
            unlink(sys_get_temp_dir() . '/test.log');
        }
        if (file_exists(sys_get_temp_dir() . '/test.json.log')) {
            unlink(sys_get_temp_dir() . '/test.json.log');
        }

        $logger = new LoggerChain([
            new FileLogger(sys_get_temp_dir() . '/test.log'),
            new JsonFileLogger(sys_get_temp_dir() . '/test.json.log'),
        ]);

        $logger->debug('This should not be logged');
        $logger->info('This should not be logged either');
        $logger->warning('This should be logged as warning');
        $logger->error('This should be logged as error');

        $data = file_get_contents(sys_get_temp_dir() . '/test.log');
        $jsonData = file_get_contents(sys_get_temp_dir() . '/test.json.log');
        if (file_exists(sys_get_temp_dir() . '/test.log')) {
            unlink(sys_get_temp_dir() . '/test.log');
        }
        if (file_exists(sys_get_temp_dir() . '/test.json.log')) {
            unlink(sys_get_temp_dir() . '/test.json.log');
        }

        $this->assertStringNotContainsString('This should not be logged', $data);
        $this->assertStringContainsString('This should be logged as warning', $data);
        $this->assertStringContainsString('This should be logged as error', $data);

        /// @todo split the json log on "\n", check that each line is valid json, and it has the expected members

        $this->assertStringNotContainsString('This should not be logged', $jsonData);
        $this->assertStringContainsString('"This should be logged as warning"', $jsonData);
        $this->assertStringContainsString('"This should be logged as error"', $jsonData);
    }

    /// @todo... add tests for ApacheLogger, ErrorLogger, FrankenPHPLogger
}
