<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use TanoWAF\WAFCore\Filter\Bidirectional\Tracer;
use TanoWAF\WAFCore\Filter\Bidirectional\ForceAcceptEncoding;
use TanoWAF\WAFCore\Filter\Bidirectional\RemoveAcceptEncoding;
use TanoWAF\WAFCore\Firewall\FirewallFactory;
use TanoWAF\WAFCore\Http\CookieParserFactory;
use TanoWAF\WAFCore\Http\HeaderParserFactory;
use TanoWAF\WAFCore\Http\QueryStringParserFactory;
use TanoWAF\WAFCore\Logger\FileLogger;
use TanoWAF\WAFCore\Middleware\Dispatcher;
use TanoWAF\WAFCore\Proxy\FixedUpstreamProxy;
use TanoWAF\WAFCore\Response\Psr7\ResponseFactory;
use TanoWAF\WAFCore\Swoole\Emitter as SwooleEmitter;
use TanoWAF\WAFCore\Swoole\ServerRequestFactory;
use TanoWAF\WAFCore\ServerRequest\Psr7\ServerRequest;
use TanoWAF\WAFCore\UpstreamClient\MiddlewareAware as MiddlewareAwareClient;

class TestWAFPage
{
    use SwooleAwareTrait;

    protected LoggerInterface|null $logger;
    protected string|null $phpunitSeleniumTestId;

    public function preFlight(): bool
    {
        $this->envFromSwooleRequest();

        // In case this file is made available on an open-access server, avoid it being useable by anyone who can not
        // also write a specific file to disk.
        // NB: keep filename, cookie name in sync with the code within the TestCase classes sending http requests to this file
        $idFile = sys_get_temp_dir() . '/phpunit_rand_id.txt';
        $randId = $_COOKIE['PHPUNIT_RANDOM_TEST_ID'] ?? '';
        $fileId = file_exists($idFile) ? file_get_contents($idFile) : '';
        if ($randId == '' || $fileId == '' || $fileId !== $randId) {
            //header('HTTP/1.1 400 Bad Request');
            $this->http_response_code(400);
            $this->echo('This url can only be accessed by the test suite');
            return false;
        }

        // Out-of-band information: let the client manipulate the page operations

        /// @todo... test: is code coverage working with swoole? we should at least replace the `include_once` calls with `include`...
        if (isset($_COOKIE['PHPUNIT_SELENIUM_TEST_ID']) && extension_loaded('xdebug')) {
            // NB: this has to be kept in sync with phpunit_coverage.php
            $GLOBALS['PHPUNIT_COVERAGE_DATA_DIRECTORY'] = sys_get_temp_dir() . '/wafcore_coverage';
            if (!is_dir($GLOBALS['PHPUNIT_COVERAGE_DATA_DIRECTORY'])) {
                mkdir($GLOBALS['PHPUNIT_COVERAGE_DATA_DIRECTORY']);
                chmod($GLOBALS['PHPUNIT_COVERAGE_DATA_DIRECTORY'], 0777);
            }

            include_once __DIR__ . '/PhpunitSelenium/prepend.php';

            $this->phpunitSeleniumTestId = $_COOKIE['PHPUNIT_SELENIUM_TEST_ID'];
            $this->removeCookieFromEnv('PHPUNIT_SELENIUM_TEST_ID');
        } else {
            $this->phpunitSeleniumTestId = null;
        }

        // Allow the caller to pick a set of configs which differ based on the upstream webserver in use
        $dotConf = new DotConf();
        $dotConf->loadEnv($_SERVER['HTTP_X_WAFCORE_SERVER_TYPE'] ?? 'nginx', $_SERVER['HTTP_X_WAFCORE_WAF_TYPE'] ?? '');

        // set up a logger whose output can be inspected by the caller
        $logger = null;
        if (array_key_exists('HTTP_X_WAFCORE_LOG_FILE', $_SERVER) && trim($_SERVER['HTTP_X_WAFCORE_LOG_FILE']) !== '') {
            $logFileName = sys_get_temp_dir() . '/' . basename($_SERVER['HTTP_X_WAFCORE_LOG_FILE']);
            /// @todo should we allow the logs + traces to be stored in a custom dir, making it easy to map it to the host filesystem?
            if (file_exists($logFileName)) {
                file_put_contents($logFileName, '');
            }
            $logger = new FileLogger($logFileName, LogLevel::DEBUG);
        }

        if ($logger) {
            $logger->debug("Loaded .conf config for SERVER_TYPE: {$_ENV['SERVER_TYPE']}");
            if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] !== '') {
                /// @todo this seems to be wrong most of the time. $_SERVER['SERVER_PORT'] is most likely built from
                ///       the received `Host` header...
                //$logger->debug("WAF listening on port: {$_SERVER['SERVER_PORT']}");
            } else {
                $logger->debug("WAF listening on a unix socket");
            }
        }

        $this->logger = $logger;

        return true;
    }

    public function postFlight(): void
    {
        if ($this->phpunitSeleniumTestId !== null) {
            $_COOKIE['PHPUNIT_SELENIUM_TEST_ID'] = $this->phpunitSeleniumTestId;
            include_once __DIR__ . '/PhpunitSelenium/append.php';
        }

        $this->cleanUpEnv();

        $this->setSwooleRequest(null);
        $this->setSwooleResponse(null);
    }

    public function handleRequest(): void
    {
        if ($this->swooleResponse === null) {
            $responseEmitter = new SapiEmitter();
        } else {
            $responseEmitter = new SwooleEmitter();
        }

        $tracer = null;
        try {

            // in case these are set, they might interfere with the configuration of the Client that gets built
            // NB: HTTP_PROXY uppercase should not be used by any clients, as it can be spoofed by an http header from clients...
            unset($_SERVER['http_proxy'], $_SERVER['HTTP_PROXY'], $_SERVER['https_proxy'], $_SERVER['HTTPS_PROXY'], $_SERVER['no_proxy'], $_SERVER['NO_PROXY']);

            // avoid php interfering with the waf sending out compressed responses
            ini_set('zlib.output_compression', 0);

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

            if (array_key_exists('HTTP_X_WAFCORE_UPSTREAM_CLIENT_TYPE', $_SERVER) && trim($_SERVER['HTTP_X_WAFCORE_UPSTREAM_CLIENT_TYPE']) !== '') {
                $this->logger->debug("Using '{$_SERVER['HTTP_X_WAFCORE_UPSTREAM_CLIENT_TYPE']}' client type to connect to upstream");
                $httpClient = TestWAF::createUpstreamClient($_SERVER['HTTP_X_WAFCORE_UPSTREAM_CLIENT_TYPE']);
            } else {
                $httpClient = TestWAF::createUpstreamClient();
            }

            $middlewareChain = new Dispatcher([]);

            if (array_key_exists('HTTP_X_WAFCORE_TRACE_FILE', $_SERVER) && trim($_SERVER['HTTP_X_WAFCORE_TRACE_FILE']) !== '') {
                $traceFileName = sys_get_temp_dir() . '/' . basename($_SERVER['HTTP_X_WAFCORE_TRACE_FILE']);
                if (file_exists($traceFileName)) {
                    file_put_contents($traceFileName, '');
                }

                // We want to put 2 tracers in the chain, one at the very start and one at the very end.
                // However, the tracer at the start would get bypassed in case the fw throws an access-denied exception.
                // So instead of adding it to the middleware chain, we run it here.
                $tracer = new Tracer($traceFileName);
                //$middlewareChain->appendMiddleware($tracer);
                $httpClient = new MiddlewareAwareClient(new Tracer($traceFileName, '>> ', '<< '), $httpClient, $this->logger);
            }

            $firewallFactory = new FirewallFactory($requestFactory, $responseFactory, $this->logger);
            $config = array_key_exists('HTTP_X_WAFCORE_CONFIG', $_SERVER) ? trim($_SERVER['HTTP_X_WAFCORE_CONFIG']) : '';
            $configFile = array_key_exists('HTTP_X_WAFCORE_CONFIG_FILE', $_SERVER) ? trim($_SERVER['HTTP_X_WAFCORE_CONFIG_FILE']) : '';
            if ($configFile !== '') {
                if ($config !== '') {
                    throw new \Exception("Can not use at the same time headers X-WAFCORE-CONFIG and X-WAFCORE-CONFIG-FILE");
                }
                if (!$this->fileIsInTestsDir('configs/' . $configFile)) {
                    throw new \Exception("Can not use config file defined in header WAFCORE-CONFIG-FILE: outside tests root");
                }
                $firewall = $firewallFactory->fromConfigFile(__DIR__ . '/configs/' . $configFile);
            } else {
                if ($config !== '') {
                    $this->logger->info('Loading firewall configuration from string received as header X-WAFCORE-CONFIG');
                }
                $firewall = $firewallFactory->fromConfigString($config);
            }
            $middlewareChain->appendMiddleware($firewall);

            // This can disable/change requesting for compressed responses - currently done both to avoid issues with
            // Body matchers and to ease troubleshooting by visual inspection of payloads
            if (array_key_exists('HTTP_X_WAFCORE_FORCE_ACCEPT_ENCODING', $_SERVER) && trim($_SERVER['HTTP_X_WAFCORE_FORCE_ACCEPT_ENCODING']) !== '') {
                if ($_SERVER['HTTP_X_WAFCORE_FORCE_ACCEPT_ENCODING'] === 'none') {
                    $this->logger->debug("Removing existing accept-encoding headers to connect to upstream");
                    $middlewareChain->appendMiddleware(new RemoveAcceptEncoding());
                } else {
                    $this->logger->debug("Forcing '{$_SERVER['HTTP_X_WAFCORE_FORCE_ACCEPT_ENCODING']}' accept-encoding header to connect to upstream");
                    $middlewareChain->appendMiddleware(new ForceAcceptEncoding($_SERVER['HTTP_X_WAFCORE_FORCE_ACCEPT_ENCODING']));
                }
            }

            // allow the scheme+port to be set via a custom http header, to test http:// vs https:// vs tcp:// vs unix:/
            /// @todo allow the caller to request for a non-existent, controlled unix socket. Also, no need to allow
            ///       _any_ port, just a known non-existent one...
            if (array_key_exists('HTTP_X_WAFCORE_UPSTREAM_SCHEME', $_SERVER) && trim($_SERVER['HTTP_X_WAFCORE_UPSTREAM_SCHEME']) !== '') {
                $upstreamUri = TestWAF::getUpstreamUri(
                    $_SERVER['HTTP_X_WAFCORE_UPSTREAM_SCHEME'],
                    (array_key_exists('HTTP_X_WAFCORE_UPSTREAM_PORT_OVERRIDE', $_SERVER) && trim($_SERVER['HTTP_X_WAFCORE_UPSTREAM_PORT_OVERRIDE']) !== '') ?
                        (int)$_SERVER['HTTP_X_WAFCORE_UPSTREAM_PORT_OVERRIDE'] : null
                );
            } else {
                $upstreamUri = TestWAF::getUpstreamUri();
            }

            /// @todo... allow more options to be set, either to the httpClient or in the middlewareChain

            $upstreamProxy = new FixedUpstreamProxy($upstreamUri, $httpClient, null, $this->logger);
            $waf = new TestWAF($middlewareChain, $upstreamProxy, $this->logger);

            $serverRequest = $this->fromGlobals($requestFactory);

            $tracer?->filterServerRequest($serverRequest);
            $response = $waf->handle($serverRequest);
            $tracer?->filterResponse($response, $serverRequest);

            if ($this->swooleResponse === null) {
                $responseEmitter->emit($response);
            } else {
                $responseEmitter->emit($response, $this->swooleResponse);
            }

        } catch (\Throwable $e) {
            $this->logger?->critical($e->getMessage() . ', in File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            $response = TestWAF::getErrorResponse($e);
            // in case there was an error, we assume that the frontline tracer did not have a chance to log the response
            if ($tracer) {
                file_put_contents($traceFileName, $tracer->serializeResponse($response), FILE_APPEND);
            }

            if ($this->swooleResponse === null) {
                $responseEmitter->emit($response);
                exit();
            } else {
                $responseEmitter->emit($response, $this->swooleResponse);
                /// @todo should we prevent execution of postFlight?
            }
        }
    }

    /**
     * Clean up ("patch") the data we allow the WAF to handle - remove test-managing headers and cookies.
     * NB: calling this results in manipulation of $_SERVER and co.
     */
    protected function fromGlobals(ServerRequestFactory $requestFactory): ServerRequest
    {
        if ($this->swooleRequest === null) {
            foreach ($_SERVER as $name => $value) {
                if (str_starts_with($name, 'HTTP_X_WAFCORE_')) {
                    unset($_SERVER[$name]);
                }
            }

            foreach ($_COOKIE as $name => $value) {
                if (str_starts_with($name, 'PHPUNIT_') && $name !== 'PHPUNIT_RANDOM_TEST_ID') {
                    $this->removeCookieFromEnv($name);
                }
            }

            return $requestFactory->fromGlobals();
        } else {
/// @todo... clean up cookies and http headers
            $headers = $this->swooleRequest->header;
            if ($headers !== null) {
                foreach ($headers as $name => $value) {
                    if (str_starts_with($name, 'X-Wafcore-')) {
                        unset($headers[$name]);
                    }
                }
                $this->swooleRequest->header = $headers;
            }

            $cookies = $this->swooleRequest->cookie;
            if ($cookies !== null) {
                foreach ($cookies as $name => $value) {
                    if (str_starts_with($name, 'PHPUNIT_') && $name !== 'PHPUNIT_RANDOM_TEST_ID') {
                        unset($cookies[$name]);
                        /// @todo should we remove the cookie from $_COOKIE and $_SERVER['HTTP_COOKIE'] too ?
                        //$this->removeCookieFromEnv($name);
                    }
                }
                $this->swooleRequest->cookie = $cookies;
            }

            return $requestFactory->fromSwooleRequest($this->swooleRequest);
        }
    }

    protected function fileIsInTestsDir($fileName): bool
    {
        if (false === ($filePath = realpath(__DIR__ . '/' . $fileName))) {
            return false;
        }
        return str_starts_with($filePath, realpath(__DIR__));
    }

    protected function removeCookieFromEnv($cookieName)
    {
        unset($_COOKIE[$cookieName]);
/// @todo... patch as well $_SERVER['HTTP_COOKIE'], as that is what is going to be used instead of $_COOKIE
    }
}
