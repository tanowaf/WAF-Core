<?php
declare(strict_types=1);

// *** A _minimalist_ php webpage, to be used for load tests. Does nothing and returns an empty page. Useful as baseline for php code.

$configFile = @$argv[1];

if (! is_file($configFile) || !extension_loaded('openswoole') || PHP_SAPI !== 'cli') {
    throw new \Exception('This script is meant to be used in Swoole Worker mode, which is not enabled in the current configuration');
}

// Swoole version

require __DIR__ . '/../../../vendor/autoload.php';

use OpenSwoole\Http\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

if (function_exists('pcntl_signal')) {
    function sigHandler($signo)
    {
        switch ($signo) {
            case SIGINT:
            case SIGQUIT:
            case SIGTERM:
                // handle shutdown tasks
                exit;
            case SIGHUP:
                /// @todo... reload the config and restart the server
                break;
            default:
                // handle all other signals
        }
    }
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, "sigHandler");
    pcntl_signal(SIGQUIT, "sigHandler");
    pcntl_signal(SIGTERM, "sigHandler");
    pcntl_signal(SIGHUP,  "sigHandler");
}

$config = array_merge(['listen_ip' => '0.0.0.0', 'listen_port' => 8084], json_decode(file_get_contents($configFile), true));

$server = new Server($config['listen_ip'], (int)$config['listen_port']);

unset($config['listen_ip'], $config['listen_port']);

/// @see https://openswoole.com/docs/modules/swoole-server/configuration
$server->set($config);

// The main HTTP server request callback event, entry point for all incoming HTTP requests
$server->on('Request', function(Request $request, Response $response)
{
    $response->end('');
});

$server->start();
