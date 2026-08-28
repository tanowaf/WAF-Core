<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\UpstreamClient;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Exception\UpstreamRequestError;
use TanoWAF\WAFCore\Exception\UpstreamRequestTimeout;

class GuzzleAdapter implements UpstreamClientInterface
{
    protected ClientInterface $guzzleClient;
    protected int|float $maxExecutionTime = 0; // in seconds

    /**
     * @throws \Exception
     */
    public function __construct(array $options = [], ClientInterface|null $guzzleClient = null) {
        if ($guzzleClient === null) {
/// @todo... when there is no 'handler' specified in the options, the Client constructor goes overkill: it gives back
///          a client with a full stack of handlers, which we do not need (two of those get neutered in any case inside
///          the `sendRequest` method, and the 'cookies' middleware we never initialize), and a series of 2-3 handlers
///          proxying each other (curl-multi/curl-exec/stream).
///          We would probably get a faster client by removing the middlewares, and we should check if the proxied handlers
///          bring any value...
            $this->guzzleClient = new Client($this->mapOptions($options));
        } else {
            $mappedOptions = $this->mapOptions($options);
            if ($mappedOptions) {
                $allOptionsOk = false;
                if (is_callable([$guzzleClient, 'getConfig'])) {
                    $allOptionsOk = true;
                    foreach ($mappedOptions as $name => $value) {
                        if ($guzzleClient->getConfig($name) !== $value) {
                            $allOptionsOk = false;
                            break;
                        }
                    }
                }
                if (!$allOptionsOk) {
                    throw new \Exception("Starting out with an existing Client is not implemented yet by the Guzzle Adapter, sorry");
                }
            }
            $this->guzzleClient = $guzzleClient;
        }
    }

    /**
     * @throws UpstreamRequestError
     * @throws UpstreamRequestTimeout
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            // NB: guzzle does transparently decompress the responses, and it gives us back the plain body, stripping the
            // Content-Encoding header
            if ($this->maxExecutionTime > 0) {
                $start = microtime(true);
                $response = $this->guzzleClient->sendRequest($request);
/// @todo... (starting w. guzzle 8) we have to force reading the whole resp. body to make sure that we trigger timeouts
                //$stream = $response->getBody();
                //if ($stream->isSeekable()) {
                //    $stream->rewind();
                //}
                //$body = $stream->getContents();
                return $response;
            } else {
                $response = $this->guzzleClient->sendRequest($request);
                return $response;
            }
        } catch (NetworkExceptionInterface $e) {
            // this is when using curl and there is a read timeout
            if (str_contains($e->getMessage(), 'Operation timed out') || str_contains($e->getMessage(), 'Connection timed out')) {
                throw new UpstreamRequestTimeout($e->getMessage(), $e->getCode(), $e);
            } else {
                throw new UpstreamRequestError($e->getMessage(), $e->getCode(), $e);
            }
        } catch (\Throwable $e) {
            // Timeouts when using the Stream handler a bit harder to detect - we get a RequestException with message
            // 'Unable to read from stream'. So we instead save the timeout options we were passed in, and, if any, check it
            if ($this->maxExecutionTime > 0 && $this->maxExecutionTime < (microtime(true) - $start)) {
                throw new UpstreamRequestTimeout($e->getMessage(), $e->getCode(), $e);
            } else {
                throw new UpstreamRequestError($e->getMessage(), $e->getCode(), $e);
            }
        }
    }

    /// @todo...
    public function withOptions(array $options): UpstreamClientInterface
    {
        throw new \Exception("withOptions is not implemented yet by the Guzzle Adapter, sorry");
    }

    /**
     * @see \GuzzleHttp\RequestOptions
     * @todo is it worth moving to Symfony option resolver?
     */
    protected function mapOptions(array $options): array
    {
        // We decode response bodies on our own, iff needed. No need to make this always happen automatically
        $mappedOptions = ['decode_content' => false];
        foreach ($options as $name => $value) {
            switch ($name) {
                case UpstreamClientInterface::OPT_BINDTO:
                    if (!defined('CURLOPT_UNIX_SOCKET_PATH')) {
                        throw new \Exception("Client option: '$name' requires availability of the Curl php extension");
                    }
                    $mappedOptions['curl'] = [CURLOPT_UNIX_SOCKET_PATH => $value] + ($mappedOptions['curl'] ?? []);
                    break;
                case UpstreamClientInterface::OPT_CONNECT_TIMEOUT:
                    $mappedOptions[RequestOptions::CONNECT_TIMEOUT] = $value;
                    break;
                case UpstreamClientInterface::OPT_TIMEOUT:
                    $mappedOptions[RequestOptions::TIMEOUT] = $value;
                    $this->maxExecutionTime = $value;
                    break;
                case UpstreamClientInterface::OPT_TRANSPORT:
                    switch($value) {
                        case 'native':
                            $mappedOptions['handler'] = new StreamHandler();
                            break;
                        case 'curl':
/// @todo... check (and try to match?) the default options used in creating the guzzle curl handler in HandlerStack::create
                            $mappedOptions['handler'] = new CurlHandler();
                            break;
                        case 'default':
                            break;
                        default:
                            throw new \Exception("Client option: '$name' has invalid value '$value'");
                    }
                    break;
                default:
                    throw new \Exception("Unsupported client option: '$name'");
            }
        }
        return $mappedOptions;
    }

    public function getUserAgent(): string
    {
        /// @todo retrieve the info about the handler in $this->client and add it here. Note that it might be complex,
        ///       as there could be a whole stack of those
        return 'GuzzleHttp ' . ClientInterface::MAJOR_VERSION;
    }
}
