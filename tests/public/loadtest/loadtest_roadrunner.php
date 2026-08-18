<?php
declare(strict_types=1);

// *** A _minimalist_ waf proxy, to be used for load tests. Returns a fixed response, without contacting any upstream.

if (!isset($_SERVER['ROADRUNNER_WORKER']) || (int)$_SERVER['ROADRUNNER_WORKER'] === 0 || PHP_SAPI !== 'cli') {
    throw new \Exception('This script is meant to be used in RoadRunner Worker mode, which is not enabled in the current configuration');
}

// RoadRunner version

require __DIR__ . '/../../../vendor/autoload.php';

use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\RoadRunner\Worker;
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

// Create new RoadRunner worker from global environment
$worker = Worker::create();
$httpWorker = new HttpWorker($worker);

try {

    $firewallFactory = new FirewallFactory();

    // one of the simplest rules that allows to easily test both allow and deny responses: look for 'y' in the query string
    $firewall = $firewallFactory->fromConfiguration([['query_string_parameter_value' => ['y' => '*']]]);

    $httpClient = new MockUpstreamClient();
    // the upstream uri is not used in this case, since the MockUpstreamClient will happily ignore it
    $upstreamProxy = new FixedUpstreamProxy('http://127.0.0.1/', $httpClient);

    $waf = new LoadTestWAF($firewall, $upstreamProxy);

    while (true) {
        try {


/// @todo... use a custom requestCreator to build our own request type out of the RR env, then serialize back the response
            //$response = $waf->handle($serverRequest);

        } catch (\Throwable $e) {
            // Although the PSR-17 specification clearly states that there can be
            // no exceptions when creating a request, however, some implementations
            // may violate this rule. Therefore, it is recommended to process the
            // incoming request for errors.
            //
            // Send "Bad Request" response.
            $httpWorker->respond(400);
            continue;
        }
    }

} catch (\Throwable $e) {
    $httpWorker->respond(500);
    $worker->error((string)$e);
}
