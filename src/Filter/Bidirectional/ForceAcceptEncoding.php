<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Http\BodyUncompressingCapableInterface;

/**
 * Used to force enabling/disabling accepting encoded (compressed) responses.
 * List of valid values: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Accept-Encoding
 */
class ForceAcceptEncoding extends RequestHeaderAdder
{
    public function __construct(string $acceptEncoding)
    {
        parent::__construct(['Accept-encoding' => $acceptEncoding]);
    }

    public function filterResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        if ($response->hasHeader('Content-Encoding') && isset($this->overriddenHeaders['Accept-Encoding'])) {
            if (!$response instanceof BodyUncompressingCapableInterface) {
                throw new \Exception("ForceAcceptEncoding needs a BodyUncompressingCapableInterface response to operate on, gotten a " . get_class($response));
            }

            $response = $response->withTranscompressedMessageBody($this->overriddenHeaders['Accept-Encoding']);

/// @todo... use normalized header values to check for the presence of Accept-Encoding
            if (!$response->hasHeader('Vary') || !in_array('Accept-Encoding', $response->getHeader('Vary'))) {
                $response = $response->withAddedHeader('Vary', 'Accept-Encoding');
            }
        }

        return $response;
    }
}
