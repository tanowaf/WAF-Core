<?php
/**
 * Used to serve back the server-side code coverage results to phpunit-selenium
 **/

$coverageFile = realpath(__DIR__ . '/../PhpunitSelenium/phpunit_coverage.php');

// has to be the same value as used in proxy.php
$GLOBALS['PHPUNIT_COVERAGE_DATA_DIRECTORY'] = sys_get_temp_dir() . '/wafcore_coverage';

if (!is_dir($GLOBALS['PHPUNIT_COVERAGE_DATA_DIRECTORY'])) {
    mkdir($GLOBALS['PHPUNIT_COVERAGE_DATA_DIRECTORY']);
}

chdir($GLOBALS['PHPUNIT_COVERAGE_DATA_DIRECTORY']);

include_once $coverageFile;
