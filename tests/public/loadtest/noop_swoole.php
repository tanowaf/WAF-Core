<?php
declare(strict_types=1);

// *** A _minimalist_ php webpage, to be used for load tests. Does nothing and returns an empty page. Useful as baseline for php code.

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

require __DIR__ . '/../../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use TanoWAF\WAFCore\Swoole\ServerFactory;

\Swoole\Runtime::setHookFlags(SWOOLE_HOOK_ALL);

$swooleConfig = Yaml::parseFile($configFile);
$serverFactory = new ServerFactory();
$server = $serverFactory->fromConfig($swooleConfig);

if ($server instanceof \Swoole\Coroutine\Http\Server) {
    \Swoole\Coroutine\run(function() use ($server) {
        /** @phpstan-ignore class.notFound */
        $server->handle('/', function(\OpenSwoole\Http\Request|\Swoole\Http\Request $request, \OpenSwoole\Http\Response|\Swoole\Http\Response $response)
        {
            $response->end();
        });

        $server->start();
    });
} else {
    // The main HTTP server request callback event, entry point for all incoming HTTP requests
    /** @phpstan-ignore class.notFound */
    $server->on('Request', function(\OpenSwoole\Http\Request|\Swoole\Http\Request $request, \OpenSwoole\Http\Response|\Swoole\Http\Response $response)
    {
        $response->end();
    });
}

$server->start();
