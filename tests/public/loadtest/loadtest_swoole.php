<?php
declare(strict_types=1);

// *** A _minimalist_ waf proxy, to be used for load tests. Returns a fixed response, without contacting any upstream.

if (!isset($_SERVER['SWOOLE_WORKER']) || (int)$_SERVER['SWOOLE_WORKER'] === 0 ||
    (!extension_loaded('openswoole') && !extension_loaded('swoole')) ||
    PHP_SAPI !== 'cli') {
    throw new \Exception('This script is meant to be used in Swoole Worker mode, which is not enabled in the current configuration');
}

// (Open)Swoole worker mode

$configFile = @$_SERVER['argv'][1];
if (!is_file($configFile) ) {
    throw new \Exception('This script has to be run passing in the json config filename as 1st argument');
}

require __DIR__ . '/../../../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Yaml\Yaml;
use TanoWAF\WAFCore\Firewall\FirewallFactory;
use TanoWAF\WAFCore\Http\CookieParserFactory;
use TanoWAF\WAFCore\Http\HeaderParserFactory;
use TanoWAF\WAFCore\Http\QueryStringParserFactory;
use TanoWAF\WAFCore\Proxy\FixedUpstreamProxy;
use TanoWAF\WAFCore\Response\Psr7\ResponseFactory;
use TanoWAF\WAFCore\Swoole\Emitter;
use TanoWAF\WAFCore\Swoole\ServerFactory;
use TanoWAF\WAFCore\Swoole\ServerRequestFactory;
use TanoWAF\WAFCore\Tests\LoadTestWAF;
use TanoWAF\WAFCore\Tests\MockUpstreamClient;

// 1. Build the WAF

$psr17Factory = new Psr17Factory();
$cookieParserFactory = new CookieParserFactory();
$headerParserFactory = new HeaderParserFactory();
$queryStringParserFactory = new QueryStringParserFactory();
$cookieParser = $cookieParserFactory->fromConfiguration([]);
$headerParser = $headerParserFactory->fromConfiguration([]);
$requestFactory = new ServerRequestFactory(
    $psr17Factory, // UriFactory
    $psr17Factory, // UploadedFileFactory
    $psr17Factory, // StreamFactory
    $cookieParser,
    $headerParser,
    $queryStringParserFactory->fromConfiguration([])
);
$responseFactory = new ResponseFactory($cookieParser, $headerParser);

$firewallFactory = new FirewallFactory($requestFactory, $responseFactory);

// one of the simplest rules that allows to easily test both allow and deny responses: look for 'y' in the query string
$firewall = $firewallFactory->fromConfiguration([['query_string_parameter_value' => ['y' => '*']]]);

$httpClient = new MockUpstreamClient();
// the upstream uri is not used in this case, since the MockUpstreamClient will happily ignore it
$upstreamProxy = new FixedUpstreamProxy('http://127.0.0.1/', $httpClient);

$waf = new LoadTestWAF($firewall, $upstreamProxy);

$emitter = new Emitter();

// 2. Build the (Open)Swoole server

$serverConfig = array_replace_recursive(['listen' => ['listen_ip' => '0.0.0.0', 'listen_port' => 8084]], Yaml::parseFile($configFile));
$serverFactory = new ServerFactory();
$server = $serverFactory->fromConfig($serverConfig);

if (isset($serverConfig['coroutine_settings']) && is_array($serverConfig['coroutine_settings']) && $serverConfig['coroutine_settings']) {
/// @todo... the coroutine settings seem to only be needed by openswoole. Swoole otoh might need a `swoole_async_set` call
    if (class_exists('\Swoole\Coroutine')) {
        \Swoole\Coroutine::set($serverConfig['coroutine_settings']);
    } else if (class_exists('\OpenSwoole\Coroutine')) {
        \OpenSwoole\Coroutine::set($serverConfig['coroutine_settings']);
    } else {
        throw new \Exception("Either the Swoole or OpenSwoole php extension must be active");
    }
}

// 3. Loop

// Method A - the most automated - sadly it does not work, as we get back an empty `$request->getUri()`.
// Also, it is only available in OpenSwoole
// See https://github.com/openswoole/ext-openswoole/issues/403

//$server->setHandler($waf);

if (method_exists($server, 'handle')) {

    // Method B - only available on OpenSwoole and not necessarily faster/better

    // OpenSwoole supports ("almost" compliant) PSR  handlers
    $server->handle(function (ServerRequestInterface $request) use ($waf, $requestFactory) {
        $wafRequest = $requestFactory->fromOpenSwooleServerRequest($request);
        return $waf->handle($wafRequest);
    });

} else {

    // Method C - the default

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
