<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

use GuzzleHttp\Psr7\ServerRequest as GuzzleServerRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use TanoWAF\WAFCore\Http\CookieParserFactory;
use TanoWAF\WAFCore\Http\HeaderParserFactory;
use TanoWAF\WAFCore\Http\QueryStringParserFactory;
use TanoWAF\WAFCore\Swoole\ServerRequestFactory;
use TanoWAF\WAFCore\Tracer\RequestTracerTrait;

class TestServer
{
    const DEFAULT_RESPONSE = ['result' => 'OK', '_GET' => [], '_POST' => [], '_COOKIE' => [], 'getallheaders' => null,
        'getHeadersFromServer' => [], 'requestBody' => null, 'serverRequest' => null];

    use RequestTracerTrait;
    use SwooleAwareTrait;

    protected string $requestPrefix = '';

    public function preFlight(): bool
    {
        $this->envFromSwooleRequest();

/// @todo... enable this after we add support for the PHPUNIT_RANDOM_TEST_ID cookie in the direct-access tests
/*
        // In case this file is made available on an open-access server, avoid it being useable by anyone who can not
        // also write a specific file to disk.
        // NB: keep filename, cookie name in sync with the code within the TestCase classes sending http requests to this file
        $idFile = sys_get_temp_dir() . '/phpunit_rand_id.txt';
        $randId = $_COOKIE['PHPUNIT_RANDOM_TEST_ID'] ?? '';
        $fileId = file_exists($idFile) ? file_get_contents($idFile) : '';
        if ($randId == '' || $fileId == '' || $fileId !== $randId) {
            $this->http_response_code(400);
            $this->echo('This url can only be accessed by the test suite');
            return false;
        }
*/

        // respond with 404 answers as a "normal" webserver would do
        if ($this->swooleRequest !== null) {
            $uri = $this->swooleRequest->server['request_uri'];
            /// @todo be more strict and only answer to known urls?
            if ($uri === '/no_such_page') {
                $this->http_response_code(404);
                return false;
            }
        }

        return true;
    }

    public function postFlight(): void
    {
        $this->cleanUpEnv();

        $this->setSwooleRequest(null);
        $this->setSwooleResponse(null);
    }

    public function respond(string $requestMethod = 'GET', string $action = 'info', array $actionArgs = []): void
    {
        // avoid php interfering with the server sending out compressed responses, just in case (but we allow the
        // webserver to do the same...)
        ini_set('zlib.output_compression', 0);

        switch ($requestMethod) {
            case 'OPTIONS':
                $this->displayOptionsResponse();
                return;
            case 'TRACE':
                $this->displayTraceResponse();
                return;
        }

        switch ($action) {
            case 'error':
                $this->displayErrorResponse($actionArgs[0] ?? 500);
                break;
            case 'redirect':
                $this->displayRedirectResponse($actionArgs[0] ?? 301);
                break;
            case 'slowloris':
                $this->displaySlowResponse($actionArgs[0] ?? 30);
                break;
            case 'info':
            default:
                $this->displayInfoResponse($actionArgs[0] ?? 'wafcore');
        }
    }

    /**
     * Displays an error response
     */
    protected function displayErrorResponse($statusCode = 500, string $message = ''): void
    {
        if ($statusCode < 400 || $statusCode > 599) {
            throw new \InvalidArgumentException("Unsupported status code for returning an error response");
        }
        $this->http_response_code($statusCode);
        $this->echo($message);
    }

    /**
     * Displays the response to an Options method
     */
    protected function displayOptionsResponse(): void
    {
        $this->http_response_code(204);
        $this->header('Allow', 'GET, HEAD, OPTIONS, POST, PUT, TRACE');
    }

    /**
     * Displays a redirection response
     */
    protected function displayRedirectResponse(int|string $statusCode = 301, string $location = '/server.php'): void
    {
        switch ((int)$statusCode) {
            case 301:
            case 302:
            case 303:
            case 307:
            case 308:
                $this->http_response_code((int)$statusCode);
                $this->header('Location', $location);
                break;
            default:
                throw new \InvalidArgumentException("Unsupported status code for returning a redirection response");
        }
    }

    protected function displaySlowResponse($duration = 30): void
    {
        if ($duration < 0 || ($duration > ini_get('max_execution_time') && ini_get('max_execution_time') > 0)) {
            throw new \InvalidArgumentException("Unsupported duration for returning a slow response: $duration vs. " . ini_get('max_execution_time'));
        }
        $end = microtime(true) + $duration;
        while (microtime(true) < $end) {
            if ($this->swooleResponse === null) {
                echo '.';
                flush();
                usleep(500000);
            } else {
                /// @todo... this does not seem to flush the partial response the the client, despite doing the same as
                ///          documented at https://openswoole.com/docs/modules/swoole-http-response
                $this->swooleResponse->write('.');
                if (class_exists('\Swoole\Coroutine\System')) {
                    \Swoole\Coroutine\System::sleep(1);
                } else {
                    \OpenSwoole\Coroutine\System::sleep(1);
                }
            }
        }
        $this->echo(":-)");
    }

    /**
     * Displays the response to a Trace method
     */
    protected function displayTraceResponse(string $serverRequestLibrary = 'wafcore'): void
    {
        $this->header('Content-Type', 'message/http');
        $this->echo($this->serializeRequest($this->buildServerRequest($serverRequestLibrary)));
    }

    /**
     * Echoes a json payload with as much info as possible about the request received, to help testing
     */
    protected function displayInfoResponse(string $serverRequestLibrary = 'wafcore'): void
    {
        $serverRequest = $this->buildServerRequest($serverRequestLibrary);

        if ($this->swooleRequest === null) {
            $requestHeaders = ServerRequestFactory::getHeadersFromServer($_SERVER);
            $requestBody = file_get_contents('php://input');
        } else {
            $requestHeaders = ServerRequestFactory::getHeadersFromSwooleRequest($this->swooleRequest);
            $requestBody = (string)$this->swooleRequest->getContent();
        }

        $response = array_merge(
            self::DEFAULT_RESPONSE,
            [
                '_GET' => $_GET,
                '_POST' => $_POST,
                '_COOKIE' => $_COOKIE,
                /// @todo add other bits of $_SERVER and $_ENV that we know are used by ServerRequestFactory::fromGlobals
                /// @todo what about $_FILES?
                'getHeadersFromServer' => $requestHeaders,
                /// @todo limit the length of the body / throw if too big
                'requestBody' => $this->decodeRequestBody($requestBody, $requestHeaders),
                'serverRequest' => [
                    'method' => $serverRequest->getMethod(),
                    'protocolversion' => $serverRequest->getProtocolVersion(),
                    'requestTarget' => $serverRequest->getRequestTarget(),
                    'URI' => (string)$serverRequest->getURI(),
                    'headers' => $serverRequest->getHeaders(),
                    'attributes' => $serverRequest->getAttributes(),
                    'cookieParams' => $serverRequest->getCookieParams(),
                    'queryParams' => $serverRequest->getQueryParams(),
                    'uploadedFiles' => $serverRequest->getUploadedFiles(),
                    'parsedBody' => $serverRequest->getParsedBody(),
                ],
                /// @todo uncomment this (and add info about versions of curl, brotli, zlib) after having enabled the
                ///       safety check for the testsuite cookie
                //'serverSoftware' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            ]
        );

        // `getallheaders` is often stubbed, so we check for it with its apache-related name
        if (function_exists('frankenphp_request_headers')) {
            $response['getallheaders'] = frankenphp_request_headers();
        } elseif (function_exists('apache_request_headers')) {
            $response['getallheaders'] = apache_request_headers();
        }

        // The request's headers, and possibly also other values, might not be valid utf8, and as such will fail
        // to be encoded as json. So we rely on php serialization, base64-encoded as an alternative.
        // NB: TAK CARE WHEN UNSERIALIZING IT, TO DISALLOW CLASS-LOADING!

        $payload = json_encode($response);
        if ($payload !== false) {
            //$response = json_last_error_msg();
            $this->header('Content-type', 'application/json');
        } else {
            $payload = base64_encode(serialize($response));
            $this->header('Content-type', 'application/php-serialized+base64');
        }

        if (($this->swooleRequest === null && @$_SERVER['REQUEST_METHOD'] === 'HEAD') ||
            ($this->swooleRequest !== null && $this->swooleRequest->getMethod() === 'HEAD')) {
            // (note that this is allowed as per RFC 9110)
            $this->header('Content-Length', (string)strlen($payload));
        } else {
            /// @todo test: would setting a CL header help avoiding using chunked-encoding when answering to http 1.0
            ///       requests with Swoole?
            $this->echo($payload);
        }
    }

    /**
     * @todo any other well known libraries we could use to build the ServerRequestInterface?
     */
    protected function buildServerRequest(string $library = 'wafcore'): ServerRequestInterface
    {
        switch ($library) {
            case 'wafcore':
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
                if ($this->swooleRequest !== null) {
                    return $requestFactory->fromSwooleRequest($this->swooleRequest);
                } else {
                    return $requestFactory->fromGlobals();
                }

            case 'guzzle':
                return GuzzleServerRequest::fromGlobals();
            case 'symfony':
                $factory = new PsrHttpFactory();
                $symfonyRequest = SymfonyRequest::createFromGlobals();
                return $factory->createRequest($symfonyRequest);
            default:
                throw new \InvalidArgumentException("Unsupported library for creating a ServerRequestInterface instance: '$library'");
        }
    }

    protected function decodeRequestBody(string $body, array $requestHeaders): mixed
    {
        if ($body !== '') {
            if (isset($requestHeaders['Content-Encoding'])) {
                switch ($requestHeaders['Content-Encoding']) {
                    case 'br':
                        $body = @brotli_uncompress($body);
                        break;
                    case 'deflate':
                        $body = @gzuncompress($body);
                        break;
                    case 'gzip':
                        $body = @gzinflate(substr($body, 10, -8));
                        break;
                    case 'zstd':
                        $body = @zstd_uncompress($body);
                        break;
                    /// @todo handle default case with at least a warning
                }
            }

            if (isset($requestHeaders['Content-Type'])) {
                switch ($requestHeaders['Content-Type']) {
                    case 'application/json':
                        return @json_decode($body);
                    /// @todo handle default case with at least a warning
                }
            }
        }

        return $body;
    }
}
