<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

/// @todo... add support for parsing the SetCookie header too
class CookieParser implements CookieParserInterface
{
    /**
     * Parses the value of the Cookie header, in _loose_ accordance with rfc6265, turning it in a key => value list.
     * When a cookie is found which does not respect the spec, the issue is reported in $errorsFound.
     * If you want to be sure that the received header is rfc-complaint, validate it 1st using HeaderSpec::COOKIE_VALUE_REGEXP
     *
     * NB: this does _not_ produce the same values as found in $_COOKIE:
     * - no conversion of spaces and dots to underscores in cookie names
     * - more tolerance of whitespace around both cookie name and value
     * - if the cookie value is surrounded by double quotes, those are stripped
     * - multiple values for the same cookie are supported
     *
     * @return string[]|array NB: if the same cookie is present multiple times, all values will be accounted for, in
     *         which case the array element will be a string[] instead of a string
     * @see https://httpwg.org/specs/rfc6265.html
     */
    public function parseCookies(string $cookieString, array|null &$errorsFound = []): array
    {
        $errorsFound = [];
        $out = [];
        $cookieString = trim($cookieString, " \t");
        foreach(explode(';', $cookieString) as $i => $cookiePair) {

            if (strlen(trim($cookiePair, " \t")) === 0) {
                $errorsFound[] = "Found empty cookie-pair";
                continue;
            }

            // the spec here says a single SP, not OWS...
            if ($i > 0 && $cookiePair[0] !== ' ') {
                $errorsFound[] = "Found cookie-pair not starting with a whitespace char";
            }

            $parts = explode('=', $cookiePair, 2);

/// @todo... add an error message if there is more OWS than a single SP on the left
            $key = trim($parts[0], " \t");

            if (count($parts) > 1) {
                $value = $parts[1];
            } else {
                $errorsFound[] = "Found cookie-pair without an equal sign";
                $value = '';
            }

/// @todo... add an error message if there is any OWS
            $value = trim($value, " \t");

            if (!preg_match('/^' . HeaderSpec::TOKEN_REGEXP . '$/', $key)) {
                $errorsFound[] = "Found cookie-pair with a non-token cookie-name";
            }
            if (!preg_match('/^(?:' . HeaderSpec::COOKIE_VALUE_REGEXP . '|"' . HeaderSpec::COOKIE_VALUE_REGEXP . '")$/', $value)) {
                $errorsFound[] = "Found cookie-pair with an invalid cookie-value";
            }

            if (($l = strlen($value)) > 1 &&  $value[0] === '"' && $value[$l-1] === '"') {
                $value = substr($value, 1, -1);
            }

            if (isset($out[$key])) {
                if (is_array($out[$key])) {
                    $out[$key][] = $value;
                } else {
                    $out[$key] = [$out[$key], $value];
                }
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
