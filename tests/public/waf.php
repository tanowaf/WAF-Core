<?php
declare(strict_types=1);

// *** A waf proxy to be used by unit tests. Forwards all requests to a fixed upstream.
// ***
// *** _Do not use for anything else!_ ***
// ***
// *** For a simpler take, look at loadtest/loadtest.php

require __DIR__ . '/../../vendor/autoload.php';

use TanoWAF\WAFCore\Tests\TestWAFPage;

// Make errors always visible
ini_set('display_errors', true);
error_reporting(E_ALL);
// Avoid php interfering with the waf sending out compressed responses - we leave it to the webserver to compress them
// (we do this here as it generates a php warning if called after a php error/warning message has been generated)
ini_set('zlib.output_compression', 0);

$testWAFPage = new TestWAFPage();

if ($testWAFPage->preFlight()) {
    $testWAFPage->handleRequest();
}
$testWAFPage->postFlight();
