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

$testWAFPage = new TestWAFPage();

if ($testWAFPage->preFlight()) {
    $testWAFPage->handleRequest();
}
$testWAFPage->postFlight();
