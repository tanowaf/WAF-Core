<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Proxy;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\ConfigurationError;
use TanoWAF\WAFCore\Exception\RequestDenied;
use TanoWAF\WAFCore\Exception\UpstreamRequestError;
use TanoWAF\WAFCore\Exception\UpstreamRequestTimeout;
use TanoWAF\WAFCore\UpstreamClient\UpstreamClientFactory;
use TanoWAF\WAFCore\UpstreamClient\UpstreamClientInterface;

class FixedUpstreamProxy extends Proxy
{
    protected array $upstream;
    protected UriFactoryInterface $uriFactory;

    /**
     * @todo what about unifying the arrays of options for $this and for the $httpClient?
     * @throws \Exception
     */
    public function __construct(string $upstream, UpstreamClientInterface|array|null $httpClient = null,
        UriFactoryInterface|null $uriFactory = null, LoggerInterface|null $logger = null)
    {
        // set first the logger
        $this->logger = $logger;
        if ($uriFactory === null) {
            $uriFactory = new Psr17Factory();
        }
        $this->uriFactory = $uriFactory;
        $this->client = $this->setUpstream($upstream, $httpClient);
        $this->overrideHeaders['User-Agent'] = 'WAFCore Proxy HttpClient' . (
            ($cua = $this->client->getUserAgent()) !== '' ? ' (' . $cua . ')' : ''
        );
    }

    /**
     * @throws \Exception
     * @todo use more specific exceptions
     */
    protected function setUpstream(string $upstream, UpstreamClientInterface|array|null $httpClient = null): UpstreamClientInterface
    {
        $upstream = trim($upstream);
        if ($upstream === '') {
            throw new ConfigurationError('Empty upstream passed in');
        }
        if (!preg_match('#^(/|unix:/|tcp://|https?://)#', $upstream, $matches)) {
            throw new ConfigurationError('Upstream not supported. Only unix sockets (paths starting with "/"), tcp sockets (urls starting with "tcp://") and http urls are');
        }
        switch ($matches[1]) {
            case 'http://':
            case 'https://':
                $this->upstream = parse_url($upstream);
                if (!isset($this->upstream['port'])) {
                    if ($this->upstream['scheme'] === 'https') {
                        $this->upstream['port'] = 443;
                    } else {
                        $this->upstream['port'] = 80;
                    }
                }
                if ($httpClient === null || is_array($httpClient)) {
                    $httpClient = (new UpstreamClientFactory())->createClient((array)$httpClient);
                }
                $this->info("Proxying http upstream '$upstream'");
                break;

            case 'tcp://':
                $this->upstream = parse_url($upstream);
                if (!isset($this->upstream['port'])) {
                    throw new ConfigurationError('Upstream not supported. Missing port');
                }
                if ($httpClient === null || is_array($httpClient)) {
                    $httpClient = (new UpstreamClientFactory())->createClient((array)$httpClient);
                }
                $this->info("Proxying tcp upstream '$upstream'");
                break;

            case '/':
            case 'unix:/':
                $this->upstream = parse_url($upstream);
                // in case we were given a plain fs path
                $this->upstream['scheme'] = 'unix';
                // 'port' is not parsed for unix urls - colons get in the path
                if (str_contains($this->upstream['path'], ':')) {
                    throw new ConfigurationError('Upstream not supported: can not have port for unix sockets');
                }
                if ($httpClient === null || is_array($httpClient)) {
                    $httpClient = (new UpstreamClientFactory())->createClient([UpstreamClientInterface::OPT_BINDTO => $this->upstream['path']] + (array)$httpClient);
                } else {
                    $httpClient = $httpClient->withOptions([UpstreamClientInterface::OPT_BINDTO => $this->upstream['path']]);
                }
                $this->info("Proxying unix socket upstream '$upstream'");
                break;

            default:
                throw new ConfigurationError("Unsupported upstream scheme: '{$matches[1]}'");
        }

        if ($this->upstream['scheme'] === 'unix' || $this->upstream['scheme'] === 'tcp') {
            if (isset($this->upstream['user']) || isset($this->upstream['pass']) || isset($this->upstream['query']) ||
                (isset($this->upstream['fragment']))) {
                /// @todo review: is this actually needed? Could we proxy those infos to a tcp / socket?
                throw new ConfigurationError("The upstream '$upstream' is not valid: either of user/pass/query/fragment is not supported for scheme '{$this->upstream['scheme']}'");
            }
        }

        return $httpClient;
    }

    /**
     * @throws RequestDenied when using a middleware-aware client, this could be thrown
     * @throws UpstreamRequestError
     * @throws UpstreamRequestTimeout
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $client = $this->client;

        $request = $this->filterRequest($request);
        if ($request instanceof ResponseInterface) {
            return $request;
        }

        switch ($this->upstream['scheme']) {
            case 'http':
            case 'https':
                // fix the scheme, host, port and path
                $uri = $this->uriFactory->createUri($request->getRequestTarget());

/// @todo... when acting as an open proxy, ie. one which is not bound to a single upstream, we should follow the rules
///          set out in https://httpwg.org/specs/rfc9112.html#rfc.section.3.2.2: use the host/port from the absolute
///          form of the uri to replace the value from Host header
                $absoluteUri = $uri
                    ->withScheme($this->upstream['scheme'])
                    ->withHost($this->upstream['host'])
                    ->withPort($this->upstream['port']);
                if (isset($this->upstream['user'])) {
                    $absoluteUri = $absoluteUri->withUserInfo($this->upstream['user'], @$this->upstream['pass']);
                }

/// @todo... what if both $this->upstream and $uri have a path? Prefix one to the other! But check for superpositions?
                $request = $request->withUri($absoluteUri);
                break;

            case 'tcp':
/// @todo... test the "rewriting" requests for this case (is it needed here or can/should it be done in setUpstream?)
                // fix the scheme, host, port and path
                $uri = $request->getUri();
                $absoluteUri = $uri
                    ->withHost($this->upstream['host'])
                    ->withPort($this->upstream['port']);
                //if (isset($this->upstream['user'])) {
                //    $absoluteUri = $absoluteUri->withUserInfo($this->upstream['user'], @$this->upstream['pass']);
                //}

/// @todo... what if both $this->upstream and $uri have a path? Prefix one to the other!
                $request = $request->withUri($absoluteUri);
                break;

            case 'unix':
                // In case the http request we get uses a hostname, avoid dns resolution so that the request goes to localhost
                $host = $request->getHeaderLine('Host');
/// @todo... match also IPV6 addresses (with optional port too!), see https://www.ietf.org/rfc/rfc2732.txt
                if (!preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}(?::[0-9]{1,5})?$/', $host)) {
                    $host = explode(':', $host, 2);
                    $host = $host[0];
                    /// @todo avoid doing this if $host is 'localhost'
/// @todo... what if $host is an IP but _not_ localhost?
                    $client = $client->withOptions([
                        UpstreamClientInterface::OPT_RESOLVE => [$host => '127.0.0.1'],
                    ]);
                }

                break;

            default:
                throw new \Exception("Unsupported upstream scheme: '{$this->upstream['scheme']}'");
        }

        $response = $this->sendRequest($client, $request);

        return $this->filterResponse($response, $request);
    }
}
