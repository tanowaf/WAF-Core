<?php
declare(strict_types=1);

// An http server to be used as upstream backend by unit tests. _Do not use for anything else!_

/// @todo... allow usage of custom http headers to force returning 40x, 50x responses, xml responses instead of json,
///          enabling/disabling http features (compressed response bodies, keepalives, etc...)

if (!isset($_SERVER['SWOOLE_WORKER']) || (int)$_SERVER['SWOOLE_WORKER'] === 0 ||
    (!extension_loaded('openswoole') && !extension_loaded('swoole')) ||
    PHP_SAPI !== 'cli') {
    throw new \Exception('This script is meant to be used in Swoole Worker mode, which is not enabled in the current configuration');
}

// (Open)Swoole worker mode

$configFile = @$_SERVER['argv'][1];
if (!is_file($configFile) ) {
    throw new \Exception('This script has to be run passing in the yaml config filename as 1st argument');
}

/// @todo we could set the process name using `cli_set_process_title`, to make it easier for daemon management tools -
///       but take care that doing that by default removes the command line args
///       Also: use getopts
//$vhostName = @$_SERVER['argv'][2];
//cli_set_process_title("swoole_$vhostName $configFile");

require __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use TanoWAF\WAFCore\Swoole\ServerFactory;
use TanoWAF\WAFCore\Tests\TestServer;

// Make errors always visible
ini_set('swoole.display_errors', true);
error_reporting(E_ALL);
// Avoid php interfering with the waf sending out compressed responses - we leave it to swoole to compress them
// (we do this here as it generates a php warning if called after a php error/warning message has been generated)
ini_set('zlib.output_compression', 0);

$testServer = new TestServer();

$swooleConfig = Yaml::parseFile($configFile);
$serverFactory = new ServerFactory();
$server = $serverFactory->fromConfig($swooleConfig);

/** @phpstan-ignore class.notFound */
$f = function(\OpenSwoole\Http\Request|\Swoole\Http\Request $request, \OpenSwoole\Http\Response|\Swoole\Http\Response $response) use ($testServer)
{
    $testServer->setSwooleRequest($request);
    $testServer->setSwooleResponse($response);

    if ($testServer->preFlight()) {
        $testServer->respond($request->getMethod(), @$request->get['action'] ?? 'info', @$request->get['action_args'] ? (array)$request->get['action_args'] : []);
    }
    if ($response->isWritable()) {
        $response->end();
    }

    $testServer->postFlight();
};

if ($server instanceof \Swoole\Coroutine\Http\Server) {
    /** @phpstan-ignore function.notFound */
    \Swoole\Coroutine\run(function() use ($server, $f) {
        $server->handle('/', $f);
        $server->start();
    });
} else {
    $server->on('Request', $f);
    $server->start();
}
