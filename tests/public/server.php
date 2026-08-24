<?php
declare(strict_types=1);

// An http server to be used as upstream backend by unit tests. _Do not use for anything else!_

/// @todo... allow usage of custom http headers to force returning 40x, 50x responses, xml responses instead of json,
///          enabling/disabling http features (compressed response bodies, keepalives, etc...)

require __DIR__ . '/../../vendor/autoload.php';

use TanoWAF\WAFCore\Tests\DotConf;
use TanoWAF\WAFCore\Tests\TestServer;

// Make errors always visible
ini_set('display_errors', true);
error_reporting(E_ALL);

//$dotConf = new DotConf();
//$dotConf->loadEnv();

$testServer = new TestServer();

if ($testServer->preFlight()) {
    $testServer->respond(@$_SERVER['REQUEST_METHOD'] ?? 'GET', @$_GET['action'] ?? 'info', @$_GET['action_args'] ? (array)$_GET['action_args'] : []);
}
