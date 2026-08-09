<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tracer;

use Psr\Http\Message\RequestInterface;

trait RequestTracerTrait
{
    public function serializeRequest(RequestInterface $request): string
    {
        $out =  $this->requestPrefix . $request->getMethod() . ' ' . $request->getRequestTarget() . ' HTTP/' . $request->getProtocolVersion() . "\n";
        foreach ($request->getHeaders() as $name => $values) {
            $out .=  $this->requestPrefix . $name . ": " . implode(", ", $values) . "\n";
        }
        $out .=  $this->requestPrefix . "\n";
        $body = (string)$request->getBody();
        if ($body !== '') {
            $out .= $body . "\n";
        }
        return $out;
    }
}
