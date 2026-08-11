<?php
declare(strict_types=1);

// *** A _minimalist_ waf proxy, to be used for load tests. Returns a fixed response, without contacting any upstream.

require __DIR__ . '/../../vendor/autoload.php';

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use TanoWAF\WAFCore\Firewall\FirewallFactory;
use TanoWAF\WAFCore\Http\CookieParserFactory;
use TanoWAF\WAFCore\Http\HeaderParserFactory;
use TanoWAF\WAFCore\Http\QueryStringParserFactory;
use TanoWAF\WAFCore\Proxy\FixedUpstreamProxy;
use TanoWAF\WAFCore\ServerRequest\Psr17\ServerRequestFactory;
use TanoWAF\WAFCore\ServerRequest\Psr7\Creator as ServerRequestCreator;
use TanoWAF\WAFCore\Tests\LoadTestProxy;
use TanoWAF\WAFCore\Tests\MockUpstreamClient;

$emitter = new SapiEmitter();
try {

    $firewallFactory = new FirewallFactory();

    // one of the simplest rules that allows to easily test both allow and deny responses: look for 'y' in the query string
    $firewall = $firewallFactory->fromConfiguration([['query_string_parameter_value' => ['y' => '*']]]);

    $httpClient = new MockUpstreamClient();
    // the upstream uri is not used in this case, since the MockUpstreamClient will happily ignore it
    $upstreamConnector = new FixedUpstreamProxy('http://127.0.0.1/', $httpClient);

    $proxy = new LoadTestProxy($firewall, $upstreamConnector);

    $psr17Factory = new Psr17Factory();
    $cookieParserFactory = new CookieParserFactory();
    $headerParserFactory = new HeaderParserFactory();
    $queryStringParserFactory = new QueryStringParserFactory();
    $creator = new ServerRequestCreator(
        $psr17Factory, // UriFactory
        new ServerRequestFactory(
            $psr17Factory, // UploadedFileFactory
            $psr17Factory, // StreamFactory
            $cookieParserFactory->fromConfiguration([]),
            $headerParserFactory->fromConfiguration([]),
            $queryStringParserFactory->fromConfiguration([])
        )
    );

    // allow an optimized execution for servers such as frankenphp which can run php code in a loop
    /// @todo... add support for the RoadRunner loop too
    if (array_key_exists('FRANKENPHP_WORKER', $_SERVER) && (int)$_SERVER['FRANKENPHP_WORKER'] !== 0) {

        $handler = function() use($creator, $proxy, $emitter) {
            $serverRequest = $creator->fromGlobals();
            $response = $proxy->handle($serverRequest);
            $emitter->emit($response);
        };

        $maxRequests = (isset($_SERVER['MAX_REQUESTS_PER_WORKER']) && $_SERVER['MAX_REQUESTS_PER_WORKER'] > 0) ? (int)$_SERVER['MAX_REQUESTS_PER_WORKER'] : PHP_INT_MAX;
        for ($nbRequests = 0; $nbRequests < $maxRequests; ++$nbRequests) {
            // NB: `set_exception_handler` is called only when the worker script ends,
            // which may be unexpected, so we could (should?) catch and handle exceptions inside $handler

            $keepRunning = \frankenphp_handle_request($handler);

            // Call the garbage collector to reduce the chances of it being triggered in the middle of a page generation
            if ($nbRequests % 10 === 0) {
                gc_collect_cycles();
            }

            if (!$keepRunning) break;
        }

    } else {

        $serverRequest = $creator->fromGlobals();
        $response = $proxy->handle($serverRequest);
        $emitter->emit($response);

    }

} catch (\Throwable $e) {
    $emitter->emit(LoadTestProxy::getErrorResponse());
    exit();
}
