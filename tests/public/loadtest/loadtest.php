<?php
declare(strict_types=1);

// *** A _minimalist_ waf proxy, to be used for load tests. Returns a fixed response, without contacting any upstream.

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

    $serverRequest = $requestFactory->fromGlobals();
    $response = $waf->handle($serverRequest);
    $responseEmitter->emit($response);

} catch (\Throwable $e) {
    $responseEmitter->emit(LoadTestWAF::getErrorResponse());
}
