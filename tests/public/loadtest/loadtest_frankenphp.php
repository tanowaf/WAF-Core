<?php
declare(strict_types=1);

// *** A _minimalist_ waf proxy, to be used for load tests. Returns a fixed response, without contacting any upstream.

if (!isset($_SERVER['FRANKENPHP_WORKER']) || (int)$_SERVER['FRANKENPHP_WORKER'] === 0) {
    throw new \Exception('This script is meant to be used in FrankenPHP Worker mode, which is not enabled in the current configuration');
}

// FrankenPHP worker mode

require __DIR__ . '/../../../vendor/autoload.php';

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use TanoWAF\WAFCore\Firewall\FirewallFactory;
use TanoWAF\WAFCore\Http\CookieParserFactory;
use TanoWAF\WAFCore\Http\HeaderParserFactory;
use TanoWAF\WAFCore\Http\QueryStringParserFactory;
use TanoWAF\WAFCore\Proxy\FixedUpstreamProxy;
use TanoWAF\WAFCore\Response\Psr7\ResponseFactory;
use TanoWAF\WAFCore\ServerRequest\Psr17\ServerRequestFactory;
use TanoWAF\WAFCore\Tests\LoadTestWAF;
use TanoWAF\WAFCore\Tests\MockUpstreamClient;

$responseEmitter = new SapiEmitter();
try {

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

    $requestHandler = function() use($requestFactory, $waf, $responseEmitter) {
        $serverRequest = $requestFactory->fromGlobals();
        $response = $waf->handle($serverRequest);
        $responseEmitter->emit($response);
    };

    $maxRequests = (isset($_SERVER['MAX_REQUESTS_PER_WORKER']) && $_SERVER['MAX_REQUESTS_PER_WORKER'] > 0) ? (int)$_SERVER['MAX_REQUESTS_PER_WORKER'] : PHP_INT_MAX;
    for ($nbRequests = 0; $nbRequests < $maxRequests; ++$nbRequests) {
        // NB: `set_exception_handler` is called only when the worker script ends,
        // which may be unexpected, so we could (should?) catch and handle exceptions inside $handler

        /** @phpstan-ignore function.notFound */
        $keepRunning = \frankenphp_handle_request($requestHandler);

        // Call the garbage collector to reduce the chances of it being triggered in the middle of a page generation
        if ($nbRequests % 10 === 0) {
            gc_collect_cycles();
        }

        if (!$keepRunning) break;
    }

} catch (\Throwable $e) {
    $responseEmitter->emit(LoadTestWAF::getErrorResponse());
}
