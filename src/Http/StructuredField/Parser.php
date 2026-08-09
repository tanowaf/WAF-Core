<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField;

use TanoWAF\WAFCore\Http\HeaderFormat;

/**
 * @todo... move away from being fully static to being a normal class and get one instance injected into the HeaderParser
 * @todo... perf improvement: to avoid string copies (substr & co.), use a single string and start/end position indexes.
 *          That should be achievable using regexp_match replacing ^ with \G and passing in an offset.
 *          Side benefit: we could add $offset to every error message.
 */
class Parser
{
    public static function parseItem(string $value, array &$errorsFound): Item|null
    {
        $len = strlen($value);

        [$item, $offset] = self::parseItemInner($value, 0, $len, true, $errorsFound);

        if ($item !== null /*&& !$errorsFound*/ && $offset < $len) {
            $errorsFound[] = "Invalid Structured Field Item found: leftover characters at offset $offset";
            return null;
        }

        return $item;
    }

    /**
     * @return Item[]
     */
    public static function parseDictionary(string $value, array &$errorsFound): array
    {
        $offset = 0;
        $len = strlen($value);

        $pieces = [];

        while ($offset < $len) {

            if (!preg_match('/^([a-z*][0-9a-z_\\-.*]*)/', substr($value, $offset), $matches)) {
                $errorsFound[] = "Invalid Structured Field Dictionary found: expected valid element name at offset $offset but did not find it";
                $pieces = [];
                break;
            }

            $key = $matches[1];
            $offset += strlen($matches[1]);

            if ($offset == $len) {
                $pieces[$key] = new Item\Boolean(true);
                break;
            }

            if ($value[$offset] === '=') {
                $offset++;
                $subErrors = [];
/// @todo... handle the case of the string ending with '=' without trying to parse '' as StructuredItem
                [$item, $newOffset] = self::parseItemInner(substr($value, $offset), 0, $len - $offset, true, $subErrors);
                $offset += $newOffset;
                if ($item !== null && !$subErrors) {
                    $pieces[$key] = $item;
                } else {
                    $errorsFound = $errorsFound + $subErrors;
                    $pieces = [];
                    break;
                }
            } else {
                $parameters = [];
                if ($value[$offset] === ';') {
                    $offset++;
                    $subErrors = [];
                    [$parameters, $newOffset] = self::parseItemParameters(substr($value, $offset), 0, $len - $offset, $subErrors);
                    $offset += $newOffset;
                    if ($subErrors) {
                        $errorsFound += $subErrors;
                        $pieces = [];
                        break;
                    }
                }
                $pieces[] = new Item\Boolean(true, $parameters);
            }

            // consume OWS after the dictionary element value, before and after the comma
            while ($offset < $len && ($value[$offset] === ' ' || $value[$offset] === "\t")) {
                $offset++;
            }
            if ($offset == $len) {
                break;
            }
            if ($value[$offset] === ',') {
                $offset++;
                while ($offset < $len && ($value[$offset] === ' ' || $value[$offset] === "\t")) {
                    $offset++;
                }
                if ($offset == $len) {
                    break;
                }
            } else {
                $errorsFound[] = "Invalid Structured Field Dictionary found: invalid char found at end of element value/parameters at offset $offset";
                $pieces = [];
                break;
            }
        }

        return $pieces;
    }

    public static function parseList(string $value, array &$errorsFound): array
    {
        $offset = 0;
        $len = strlen($value);

        $pieces = [];

        while ($offset < $len) {

            $subErrors = [];
            [$item, $newOffset] = self::parseItemInner(substr($value, $offset), 0, $len - $offset, true, $subErrors);
            $offset += $newOffset;
            if ($item !== null && !$subErrors) {
                $pieces[] = $item;
            } else {
                $errorsFound = $errorsFound + $subErrors;
                $pieces = [];
                break;
            }

            // consume OWS after the dictionary element value, before and after the comma
            while ($offset < $len && ($value[$offset] === ' ' || $value[$offset] === "\t")) {
                $offset++;
            }
            if ($offset == $len) {
                break;
            }
            if ($value[$offset] === ',') {
                $offset++;
                while ($offset < $len && ($value[$offset] === ' ' || $value[$offset] === "\t")) {
                    $offset++;
                }
                if ($offset == $len) {
                    break;
                }
            } else {
                $errorsFound[] = "Invalid Structured Field List found: invalid char found at end of element value/parameters at offset $offset";
                $pieces = [];
                break;
            }
        }

        return $pieces;
    }

    /**
     * @param string $value
     * @param array $errorsFound
     * @return array Item|Parameter|null, int (the offset of the unparsed part left within $value)
     */
    /*protected static function parseParameter(string $value, array &$errorsFound): array
    {
        return self::parseItemInner($value, false, $errorsFound);
    }*/

    /**
     * @see https://httpwg.org/specs/rfc9651.html#rfc.section.4.2.3
     * @param bool $isItem when false, we are looking for a Parameter's value
     * @return array Item|Parameter|null, int (the offset of the unparsed part left within $value. NB: 0 returned when )
     */
    protected static function parseItemInner(string $value, int $offset, int $len, bool $isItem, array &$errorsFound): array
    {
        $foundType = null;
        $parsedValue = null;
        $parameters = [];

        if (($len - $offset) === 1 && in_array($value[$offset], ['-', '"', ':', '?', '@', '%'])) {
            $errorsFound[] = "Invalid Structured Field Item found: a single '{$value}' is not a valid value, at offset $offset";
            return [null, $offset];
        }

        switch ($value[$offset]) {
            case '-':
            case '0':
            case '1':
            case '2':
            case '3':
            case '4':
            case '5':
            case '6':
            case '7':
            case '8':
            case '9':
                if (preg_match('/^(-?\d+(?:\.\d+)?)/', $value, $matches)) {
                    $offset += strlen($matches[1]);
                    if (str_contains($matches[1], '.')) {
                        $foundType = HeaderFormat::SFDecimal;
                        /// @todo... check if php float range is sufficient for the rfc
                        $parsedValue = (float)$matches[1];
                    } else {
                        $foundType = HeaderFormat::SFInteger;
                        /// @todo... check if php float range is sufficient for the rfc
                        $parsedValue = (int)$matches[1];
                    }
                } else {
                    //$offset++;
                    $errorsFound[] = 'Invalid Structured Field Item found: dash followed by a non-number';
                }
                break;
            case '"':
                /// @todo validation (but not parsing) could probably be replaced with a regexp
                //$offset++;
                $parsedValue = '';
                for ($i = $offset + 1; $i < $len; $i++) {
                    switch($value[$i]) {
                        case '\\':
                            if ($i + 1 < $len && ($value[$i+1] === '\\' || $value[$i+1] === '"')) {
                                $parsedValue .= $value[$i+1];
                                $i++;
                            } else {
                                //$offset = $i;
                                $errorsFound[] = "Invalid string Structured Field Item found: invalid use of \\";
                                break 2;
                            }
                            break;
                        case '"':
                            $offset = $i + 1;
                            $foundType = HeaderFormat::SFString;
                            break 2;
                        default:
                            $code = ord($value[$i]);
                            if ($code <= 31 || $code >= 127) {
                                //$offset = $i;
                                $errorsFound[] = "Invalid string Structured Field Item found: invalid char nr. $code";
                                break 2;
                            }
                            $parsedValue .= $value[$i];
                            break;
                    }
                }
                if ($foundType === null) {
                    $errorsFound[] = 'Invalid string Structured Field Item found: missing closing double quote?';
                }
                break;
            case ':':
                if (preg_match('#^:([0-9A-Za-z+/=]*):#', $value, $matches)) {
                    $offset += strlen($matches[1]) + 2;
                    $foundType = HeaderFormat::SFByteSequence;
                    /// @todo... apply base64 decoding to test validity?
                    $parsedValue = $matches[1];
                } else {
                    //$offset++;
                    $errorsFound[] = 'Invalid byte sequence Structured Field Item found: missing closing colon?';
                }
                break;
            case '?':
                if ($value[$offset+1] === '0' || $value[$offset+1] === '1') {
                    $foundType = HeaderFormat::SFBoolean;
                    $parsedValue = ($value[$offset+1] === '1');
                    $offset += 2;
                } else {
                    //$offset++;
                    $errorsFound[] = 'Invalid boolean Structured Field Item found: neither 0 nor 1';
                }
                break;
            case '@':
                if (preg_match('/^@([0-9]+)/', $value, $matches)) {
                    $offset += strlen($matches[1]) + 1;
                    $foundType = HeaderFormat::SFDate;
                    /// @todo convert to DateTime?
                    $parsedValue = (int)$matches[1];
                } else {
                    //$offset++;
                    $errorsFound[] = 'Invalid date Structured Field Item found: spurious @ character?';
                }
                break;
            case '%':
                if ($value[$offset + 1] === '"') {
                    /// @todo validation (but not parsing) could probably be replaced with a regexp
                    $parsedValue = '';
                    for ($i = $offset+2; $i < $len; $i++) {
                        switch ($value[$i]) {
                            case '%':
                                if ($i + 2 < $len && (preg_match('/^([0-9a-f]{2})/', substr($value, $i + 1, 2), $matches))) {
                                    $i = $i + 2;
                                    $parsedValue .= hexdec($matches[1]);
                                } else {
                                    $errorsFound[] = "Invalid display string Structured Field Item found: invalid % escaping sequence found";
                                    break 2;
                                }
                                break;
                            case '"':
                                $offset = $i + 1;
                                $foundType = HeaderFormat::SFDisplayString;
                                /// @todo... check that value found is valid unicode
                                break 2;
                            default:
                                $code = ord($value[$i]);
                                // any VCHAR (except for % and ")
                                if ($code <= 31 || $code >= 127) {
                                    $errorsFound[] = "Invalid display string Structured Field Item found: invalid char nr. $code";
                                    break 2;
                                }
                                $parsedValue .= $value[$i];
                                break;
                        }
                    }
                    if ($foundType === null) {
                        $errorsFound[] = 'Invalid display string Structured Field Item found: missing closing double quote?';
                    }
                } else {
                    //$offset++;
                    $errorsFound[] = 'Invalid display string Structured Field Item found: spurious % character?';
                }
                break;
            default:
                if (preg_match('=^([A-Za-z*][0-9A-Za-z!#$%&\'*+\\-.^_`|~:/]*)=', $value, $matches)) {
                    $foundType = HeaderFormat::SFToken;
                    $offset += strlen($matches[1]);
                    $parsedValue = $matches[1];
                } else {
                    $errorsFound[] = 'Invalid Structured Field Item found: invalid first character';
                }
        }

        if ($foundType === null) {
            return [null, $offset];
        }

        if ($isItem && !$errorsFound && $offset < $len && $value[$offset] === ';') {
            $offset++;
            $parametersErrors = [];
            [$parameters, $newOffset] = self::parseItemParameters(substr($value, $offset), 0, $len - $offset, $parametersErrors);
            $offset += $newOffset;
            if ($parametersErrors) {
                $errorsFound += $parametersErrors;
                return [null, $offset];
            }
        }

        if ($isItem) {
            return [ItemFactory::create($foundType, $parsedValue, $parameters), $offset];
        } else {
            return [ParameterFactory::create($foundType, $parsedValue), $offset];
        }
    }

    /**
     * @return array Parameter[], int
     */
    protected static function parseItemParameters(string $value, int $offset, int $len, array &$errorsFound): array
    {
        $pieces = [];

        while ($offset < $len) {
            if (preg_match('/^( *[a-z*][0-9a-z_\\-.*]*)/', substr($value, $offset), $matches)) {
                $key = ltrim(' ', $matches[1]);
                $offset += strlen($matches[1]);
                if ($offset == $len) {
                    $pieces[$key] = new Parameter\Boolean(true);
                    break;
                }
                if ($value[$offset] === ';') {
/// @todo... check the spec: is it probably not ok if the string ends with ';'
                    $pieces[$key] = new Parameter\Boolean(true);
                    $offset++;
                    continue;
                }
                if ($value[$offset] === '=') {
                    $offset++;
                    $subErrors = [];
/// @todo... handle the case of the string ending with '=' without trying to parse '' as StructuredParameter
                    [$param, $newOffset] = self::parseItemInner(substr($value, $offset), 0, $len - $offset, false, $subErrors);
                    $offset += $newOffset;
                    if ($param !== null && !$subErrors) {
                        if ($offset == $len || $value[$offset] === ';') {
                            $pieces[$key] = $param;
                            if ($offset == $len) {
                                break;
                            }
                            $offset++;
                        } else {
                            // this is not necessarily an error condition: the parameters might be part of a list or dictionary
                            //$errorsFound[] = 'Invalid Structured Field Item found: invalid char found at end of parameter value';
                            break;
                        }
                    } else {
                        $errorsFound = $errorsFound + $subErrors;
                        break;
                    }
                }
            } else {
                $errorsFound[] = 'Invalid Structured Field Item found: expected valid parameter name but did not find it';
                break;
            }
        }

        return [$pieces, $offset];
    }
}
