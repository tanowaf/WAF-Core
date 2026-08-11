<?php
declare(strict_types=1);

// *** A waf proxy to be used for load tests. Returns a fixed response, without contacting any upstream.

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
    /// @todo... allow working in always-ok and always-ko mode
    if (isset($_GET['y'])) {
        $firewall = $firewallFactory->fromConfiguration([['http_method' => 'GET']]);
    } else {
        $firewall = $firewallFactory->fromConfiguration([]);
    }
    $headerParserFactory = new HeaderParserFactory();
    $headerParser = $headerParserFactory->fromConfiguration([]);
    $firewall->setHeaderParser($headerParser);

    $httpClient = new MockUpstreamClient();
    // the upstream uri is not used in this case, since the $httpClient will happily ignore it
    $upstreamConnector = new FixedUpstreamProxy('http://127.0.0.1/', $httpClient);

    $proxy = new LoadTestProxy($firewall, $upstreamConnector);

    $psr17Factory = new Psr17Factory();
    $cookieParserFactory = new CookieParserFactory();
    $queryStringParserFactory = new QueryStringParserFactory();
    $creator = new ServerRequestCreator(
        $psr17Factory, // UriFactory
        new ServerRequestFactory(
            $psr17Factory, // UploadedFileFactory
            $psr17Factory, // StreamFactory,
            $cookieParserFactory->fromConfiguration([]),
            $headerParser,
            $queryStringParserFactory->fromConfiguration([])
        )
    );

} catch (\Throwable $e) {
    $emitter->emit(LoadTestProxy::getErrorResponse());
    exit();
}

// optimize the execution loop for servers such as frankenphp which run the code in a loop
$handler = function() use($creator, $proxy, $emitter) {
    $serverRequest = $creator->fromGlobals();
    $response = $proxy->handle($serverRequest);
    $emitter->emit($response);
};

/// @todo... add support for the RoadRunner loop too

if (!array_key_exists('FRANKENPHP_WORKER', $_SERVER) || (int)$_SERVER['FRANKENPHP_WORKER'] == 0) {
    $handler();
} else {
    $maxRequests = (int)($_SERVER['MAX_REQUESTS_PER_WORKER'] ?? 0);
    for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
        // NB: `set_exception_handler` is called only when the worker script ends,
        // which may be unexpected, so we could (should?) catch and handle exceptions inside $handler

        /** @noinspection PhpUndefinedFunctionInspection */
        /** @phpstan-ignore function.notFound */
        $keepRunning = \frankenphp_handle_request($handler);

        // Call the garbage collector to reduce the chances of it being triggered in the middle of a page generation
        /// @todo do this every N requests?
        gc_collect_cycles();

        if (!$keepRunning) break;
    }
}
