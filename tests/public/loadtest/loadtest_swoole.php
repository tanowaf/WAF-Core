<?php
declare(strict_types=1);

// *** A _minimalist_ waf proxy, to be used for load tests. Returns a fixed response, without contacting any upstream.

$configFile = @$argv[1];

if (! is_file($configFile) || !extension_loaded('openswoole') || PHP_SAPI !== 'cli') {
    throw new \Exception('This script is meant to be used in Swoole Worker mode, which is not enabled in the current configuration');
}

// RoadRunner version

require __DIR__ . '/../../../vendor/autoload.php';

use OpenSwoole\Http\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use TanoWAF\WAFCore\Firewall\FirewallFactory;
use TanoWAF\WAFCore\Proxy\FixedUpstreamProxy;
use TanoWAF\WAFCore\Tests\LoadTestWAF;
use TanoWAF\WAFCore\Tests\MockUpstreamClient;

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

$firewallFactory = new FirewallFactory();

// one of the simplest rules that allows to easily test both allow and deny responses: look for 'y' in the query string
$firewall = $firewallFactory->fromConfiguration([['query_string_parameter_value' => ['y' => '*']]]);

$httpClient = new MockUpstreamClient();
// the upstream uri is not used in this case, since the MockUpstreamClient will happily ignore it
$upstreamProxy = new FixedUpstreamProxy('http://127.0.0.1/', $httpClient);

$waf = new LoadTestWAF($firewall, $upstreamProxy);

// The main HTTP server request callback event, entry point for all incoming HTTP requests
$server->on('Request', function(Request $request, Response $response) use ($waf)
{
    /// @todo... use a custom requestCreator to build our own request type out of the swoole env, then serialize back the response
    //$response->end('');
});

$server->start();
