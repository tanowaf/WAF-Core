<?php
declare(strict_types=1);

// *** A _minimalist_ waf proxy, to be used for load tests. Returns a fixed response, without contacting any upstream.

if (!isset($_SERVER['SWOOLE_WORKER']) || (int)$_SERVER['SWOOLE_WORKER'] === 0 ||
    (!extension_loaded('openswoole') && !extension_loaded('swoole')) ||
    PHP_SAPI !== 'cli') {
    throw new \Exception('This script is meant to be used in Swoole Worker mode, which is not enabled in the current configuration');
}

$configFile = @$argv[1];
if (!is_file($configFile) ) {
    throw new \Exception('This script has to be run passing in the json config filename as 1st argument');
}

$logFile = null;
if ($argc > 2) {
    $logFile = $argv[2];
}

// (Open)Swoole worker mode

require __DIR__ . '/../../../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
//use OpenSwoole\Http\Server;
//use OpenSwoole\Http\Request;
//use OpenSwoole\Http\Response;
use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Firewall\FirewallFactory;
use TanoWAF\WAFCore\Http\CookieParserFactory;
use TanoWAF\WAFCore\Http\HeaderParserFactory;
use TanoWAF\WAFCore\Http\QueryStringParserFactory;
use TanoWAF\WAFCore\Proxy\FixedUpstreamProxy;
use TanoWAF\WAFCore\Swoole\Emitter;
use TanoWAF\WAFCore\Swoole\ServerRequestFactory;
use TanoWAF\WAFCore\Tests\LoadTestWAF;
use TanoWAF\WAFCore\Tests\MockUpstreamClient;

// 1. Set up signal handling

/*
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
*/

// 2. Build the WAF

$psr17Factory = new Psr17Factory();
$cookieParserFactory = new CookieParserFactory();
$headerParserFactory = new HeaderParserFactory();
$queryStringParserFactory = new QueryStringParserFactory();
$requestFactory = new ServerRequestFactory(
    $psr17Factory, // UriFactory
    $psr17Factory, // UploadedFileFactory
    $psr17Factory, // StreamFactory
    $cookieParserFactory->fromConfiguration([]),
    $headerParserFactory->fromConfiguration([]),
    $queryStringParserFactory->fromConfiguration([])
);

$firewallFactory = new FirewallFactory();

// one of the simplest rules that allows to easily test both allow and deny responses: look for 'y' in the query string
$firewall = $firewallFactory->fromConfiguration([['query_string_parameter_value' => ['y' => '*']]]);

$httpClient = new MockUpstreamClient();
// the upstream uri is not used in this case, since the MockUpstreamClient will happily ignore it
$upstreamProxy = new FixedUpstreamProxy('http://127.0.0.1/', $httpClient);

$waf = new LoadTestWAF($firewall, $upstreamProxy);

$emitter = new Emitter();

// 3. Build the (Open)Swoole server

if ($logFile !== null) {
    $config['log_file'] = $logFile;
}

$config = array_merge(['listen_ip' => '0.0.0.0', 'listen_port' => 8084], json_decode(file_get_contents($configFile), true));

if (class_exists('\OpenSwoole\Http\Server')) {
    $serverClass = '\OpenSwoole\Http\Server';
} else if (class_exists('\Swoole\Http\Server')) {
    $serverClass = '\Swoole\Http\Server';
} else {
    throw new \Exception("This should never happen");
}

$server = new $serverClass($config['listen_ip'], (int)$config['listen_port']);
unset($config['listen_ip'], $config['listen_port']);

/// @see https://openswoole.com/docs/modules/swoole-server/configuration
$server->set($config);

// 4. Loop

// *** Method A - the most automated - sadly it does not work, as we get back an empty `$request->getUri()`. See https://github.com/openswoole/ext-openswoole/issues/403

//$server->setHandler($waf);

// *** Method B - useful to debug the Request

/// @todo... more testing for the Swoole parsing of cookies, QS and co...

if (method_exists($server, 'handle')) {

    // OpenSwoole supports ("almost" compliant) PSR  handlers
    $server->handle(function (ServerRequestInterface $request) use ($waf, $requestFactory) {
        $wafRequest = $requestFactory->fromOpenSwooleServerRequest($request);
        return $waf->handle($wafRequest);
    });

} else {

    $server->on('Request', function(\OpenSwoole\Http\Request|\Swoole\Http\Request $request, \OpenSwoole\Http\Response|\Swoole\Http\Response $response) use ($requestFactory, $waf, $emitter)
    {
        try {
            $wafRequest = $requestFactory->fromSwooleRequest($request);
            $wafResponse = $waf->handle($wafRequest);
        } catch (\Throwable $e) {
            $wafResponse = $waf::getErrorResponse();
        }
        $emitter->emit($wafResponse, $response);
    });

}

$server->start();
