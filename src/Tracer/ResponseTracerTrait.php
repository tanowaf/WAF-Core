<?php

namespace TanoWAF\WAFCore\Tracer;

use Psr\Http\Message\ResponseInterface;

trait ResponseTracerTrait
{
    public function serializeResponse(ResponseInterface $response): string
    {
        $out =  $this->responsePrefix . 'HTTP/' . $response->getProtocolVersion() . ' ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase() . "\n";
        foreach ($response->getHeaders() as $name => $values) {
            $out .= $this->responsePrefix . $name . ": " . implode(", ", $values) . "\n";
        }
        $out .= $this->responsePrefix . "\n";
        $body = (string)$response->getBody();
        if ($body !== '') {
            $out .= $body . "\n";
        }
        return $out;
    }
}
