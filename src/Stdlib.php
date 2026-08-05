<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore;

class Stdlib
{
    public static function array_of(array $array, string $className): bool
    {
        foreach ($array as $item) {
            if (!$item instanceof $className) {
                return false;
            }
        }
        return true;
    }

    public static function array_of_array(array $array): bool
    {
        foreach ($array as $item) {
            if (!is_array($item)) {
                return false;
            }
        }
        return true;
    }

    public static function array_of_int(array $array): bool
    {
        foreach ($array as $item) {
            if (!is_int($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Improved version of code from Nyholm\Psr7Server\ServerRequestCreator::getHeadersFromServer(), originally from
     * Laminas\Diactoros\marshalHeadersFromSapi().
     * @todo... test differences with https://github.com/ralouphie/getallheaders/blob/develop/src/getallheaders.php for hackish cases
     *          (see also the comments in https://www.php.net/manual/en/function.apache-request-headers.php)
     *          For a start, we should add an `ucwords` call to be compatible...
     *          Also, we should replace spaces in header names (in case someone edited $server by hand)
     */
    public static function getHeadersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            // Apache prefixes environment variables with REDIRECT_
            // if they are added by rewrite rules
            if (\str_starts_with($key, 'REDIRECT_')) {
                $key = \substr($key, 9);

                // We will not overwrite existing variables with the
                // prefixed versions, though
                if (\array_key_exists($key, $server)) {
                    continue;
                }
            }

            // yawaf change: `if ($value)` changed to `$value !== ''` (fix issue #67)

            if ($value !== '' && \str_starts_with($key, 'HTTP_')) {
                // yawaf change: make the generated headers use Snake-Case
                //$name = \strtr(\strtolower(\substr($key, 5)), '_', '-');
                $name = str_replace(' ', '-', \ucwords(\strtolower(\str_replace('_', ' ', \substr($key, 5)))));
                $headers[$name] = $value;

                continue;
            }

            /// @todo... limit this to CONTENT_TYPE, CONTENT_LENGTH, CONTENT_MD5?
            if ($value !== '' && \str_starts_with($key, 'CONTENT_')) {
                $name = 'Content-'.\ucfirst(\strtolower(\substr($key, 8)));
                $headers[$name] = $value;

                //continue;
            }
        }

        /// @todo do we have to uncomment this?
        /*if (!isset($headers['Authorization'])) {
            if (isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($server['PHP_AUTH_USER'])) {
                $basic_pass = isset($server['PHP_AUTH_PW']) ? $server['PHP_AUTH_PW'] : '';
                $headers['Authorization'] = 'Basic ' . base64_encode($server['PHP_AUTH_USER'] . ':' . $basic_pass);
            } elseif (isset($server['PHP_AUTH_DIGEST'])) {
                $headers['Authorization'] = $server['PHP_AUTH_DIGEST'];
            }
        }*/

        return $headers;
    }
}
