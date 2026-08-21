<?php
declare(strict_types=1);

// *** A _minimalist_ waf proxy, to be used for load tests. Returns a fixed response, without contacting any upstream.

if (!isset($_SERVER['ROADRUNNER_WORKER']) || (int)$_SERVER['ROADRUNNER_WORKER'] === 0 || PHP_SAPI !== 'cli') {
    throw new \Exception('This script is meant to be used in RoadRunner Worker mode, which is not enabled in the current configuration');
}

// RoadRunner worker mode

require __DIR__ . '/../../../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Worker;
use TanoWAF\WAFCore\Firewall\FirewallFactory;
use TanoWAF\WAFCore\Http\CookieParserFactory;
use TanoWAF\WAFCore\Http\HeaderParserFactory;
use TanoWAF\WAFCore\Http\QueryStringParserFactory;
use TanoWAF\WAFCore\Proxy\FixedUpstreamProxy;
use TanoWAF\WAFCore\Response\Psr7\ResponseFactory;
use TanoWAF\WAFCore\RoadRunner\ServerRequestFactory;
use TanoWAF\WAFCore\RoadRunner\Worker as HttpWorker;
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

// Create the WAF

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

// Create new RoadRunner worker
$worker = Worker::create();
$httpWorker = new HttpWorker($worker, $requestFactory);

while (true) {
    try {
        $request = $httpWorker->waitRequest();
        if ($request === null) {
            break;
        }
        $response = $waf->handle($request);
    } catch (\Throwable $e) {
        $httpWorker->respond(LoadTestWAF::getErrorResponse());
        continue;
    }

    try {
        $httpWorker->respond($response);
    } catch (\Throwable $e) {
        $httpWorker->respond(LoadTestWAF::getErrorResponse());
        $worker->error((string)$e);
    }
}
