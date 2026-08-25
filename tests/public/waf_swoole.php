<?php
declare(strict_types=1);

// *** A waf proxy to be used by unit tests. Forwards all requests to a fixed upstream.
// ***
// *** _Do not use for anything else!_ ***
// ***
// *** For a simpler take, look at loadtest/loadtest.php

if (!isset($_SERVER['SWOOLE_WORKER']) || (int)$_SERVER['SWOOLE_WORKER'] === 0 ||
    (!extension_loaded('openswoole') && !extension_loaded('swoole')) ||
    PHP_SAPI !== 'cli') {
    throw new \Exception('This script is meant to be used in Swoole Worker mode, which is not enabled in the current configuration');
}

// (Open)Swoole worker mode

$configFile = @$argv[1];
if (!is_file($configFile) ) {
    throw new \Exception('This script has to be run passing in the json config filename as 1st argument');
}

/// @todo we could set the process name using `cli_set_process_title`, to make it easier for daemon management tools -
///       but take care that doing that by default removes the command line args
//$vhostName = @$argv[2];
//cli_set_process_title("swoole_$vhostName $configFile");

require __DIR__ . '/../../vendor/autoload.php';

use TanoWAF\WAFCore\Swoole\ServerFactory;
use TanoWAF\WAFCore\Tests\TestWAFPage;

// Make errors always visible
ini_set('swoole.display_errors', true);
error_reporting(E_ALL);
// Avoid php interfering with the waf sending out compressed responses - we leave it to the webserver to compress them
// (we do this here as it generates a php warning if called after a php error/warning message has been generated)
ini_set('zlib.output_compression', 0);

$testWAFPage = new TestWAFPage();

$serverConfig = array_merge(['listen_ip' => '0.0.0.0', 'listen_port' => 8084], json_decode(file_get_contents($configFile), true));
$serverFactory = new ServerFactory();
$server = $serverFactory->fromConfig($serverConfig);

$server->on('Request', function(\OpenSwoole\Http\Request|\Swoole\Http\Request $request, \OpenSwoole\Http\Response|\Swoole\Http\Response $response) use ($testWAFPage)
{
    $testWAFPage->setSwooleRequest($request);
    $testWAFPage->setSwooleResponse($response);

    if ($testWAFPage->preFlight()) {
        $testWAFPage->handleRequest();
    }
    if ($response->isWritable()) {
        $response->end();
    }

    $testWAFPage->postFlight();
});

$server->start();
