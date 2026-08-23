<?php
declare(strict_types=1);

// *** A _minimalist_ php webpage, to be used for load tests. Does nothing and returns an empty page. Useful as baseline for php code.

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

require __DIR__ . '/../../../vendor/autoload.php';

$serverConfig = array_merge(['listen_ip' => '0.0.0.0', 'listen_port' => 8084], json_decode(file_get_contents($configFile), true));

if (class_exists('\Swoole\Http\Server')) {
    $serverClass = '\Swoole\Http\Server';
} else if (class_exists('\OpenSwoole\Http\Server')) {
    $serverClass = '\OpenSwoole\Http\Server';
} else {
    throw new \Exception("This should never happen");
}

$server = new $serverClass($serverConfig['listen_ip'], (int)$serverConfig['listen_port']);
unset($serverConfig['listen_ip'], $serverConfig['listen_port']);

/// @see https://wiki.swoole.com/en/#/http_server?id=configuration-options
/// @see https://openswoole.com/docs/modules/swoole-server/configuration
$server->set($serverConfig);

// The main HTTP server request callback event, entry point for all incoming HTTP requests
$server->on('Request', function(\OpenSwoole\Http\Request|\Swoole\Http\Request $request, \OpenSwoole\Http\Response|\Swoole\Http\Response $response)
{
    $response->end('');
});

$server->start();
