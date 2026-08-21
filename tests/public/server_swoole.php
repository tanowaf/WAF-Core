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

$configFile = @$argv[1];
if (!is_file($configFile) ) {
    throw new \Exception('This script has to be run passing in the json config filename as 1st argument');
}

require __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use TanoWAF\WAFCore\Tests\TestServer;

// NB: atm this, unlike waf.php, will not load env vars from server-specific config files .env.nginx and co...
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__.'/../.env');

$testServer = new TestServer();
$testServer->preflight();

$config = array_merge(['listen_ip' => '0.0.0.0', 'listen_port' => 8084], json_decode(file_get_contents($configFile), true));

if (class_exists('\Swoole\Http\Server')) {
    $serverClass = '\Swoole\Http\Server';
} else if (class_exists('\OpenSwoole\Http\Server')) {
    $serverClass = '\OpenSwoole\Http\Server';
} else {
    throw new \Exception("This should never happen");
}

$server = new $serverClass($config['listen_ip'], (int)$config['listen_port']);
unset($config['listen_ip'], $config['listen_port']);

/// @see https://wiki.swoole.com/en/#/http_server?id=configuration-options
/// @see https://openswoole.com/docs/modules/swoole-server/configuration
$server->set($config);

$server->on('Request', function(\OpenSwoole\Http\Request|\Swoole\Http\Request $request, \OpenSwoole\Http\Response|\Swoole\Http\Response $response) use ($testServer)
{
    $testServer->setSwooleRequest($request);
    $testServer->setSwooleResponse($response);
    $testServer->respond($request->getMethod(), @$request->get['action'] ?? 'info', @$request->get['action_args'] ? (array)$request->get['action_args'] : []);
    $response->end();
    $testServer->setSwooleRequest(null);
    $testServer->setSwooleResponse(null);

});

$server->start();
