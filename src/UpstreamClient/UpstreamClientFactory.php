<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\UpstreamClient;

class UpstreamClientFactory
{
    /**
     * @throws \Exception
     */
    public function createClient(array $options = []): UpstreamClientInterface
    {
        if (class_exists('Symfony\Component\HttpClient\HttpClient')) {
            return new SymfonyHttpClientAdapter($options);
        }

        if (class_exists('GuzzleHttp\Client')) {
            return new GuzzleAdapter($options);
        }

        throw new \Exception("Please install either guzzlehttp/guzzle or symfony/http-client");
    }
}
