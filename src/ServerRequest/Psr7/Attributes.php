<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr7;

// A trimmed-down version of Symfony's ParameterBag
class Attributes
{
    const REQUEST_METHOD_SYNTHETIC = 'rms'; // bool. Set to true when the request's method was synthetically generated (as GET)
    const SERVER_PROTOCOL_SYNTHETIC = 'sps'; // bool. Set to true when the request's protocol version was synthetically generated
    const URI_SCHEME_SYNTHETIC = 'uss'; // bool. Set to true when the request's uri scheme version was synthetically generated
    const URI_HOST_SYNTHETIC = 'uhs'; // bool. Set to true when the request's uri host was synthetically generated (ie. not from the Host header)
    const URI_PORT_SYNTHETIC = 'ups'; // bool. Set to true when the request's uri port was synthetically generated (ie. not from the Host header)
    const REMOTE_ADDR = 'ra'; // string
    const REMOTE_PORT = 'rp'; // string|int
    const SERVER_NAME = 'sn'; // string
    const SERVER_PORT = 'sp'; // string|int
    const MISSING_HOST_HEADER = 'mhh'; // bool.
    const MISSING_REQUEST_URI = 'mru'; // bool. Set to true when $_SERVER['REQUEST_URI'] is missing
    const ABSOLUTE_REQUEST_URI = 'aru'; // string. Set to the value of $_SERVER['REQUEST_URI'] when it is an absolute uri
    //const UNCOMPRESSED_REQUEST_BODY = 'urb'; // string

    protected array $attributes = [];

    public function set(string|int $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function get(string|int $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }
}
