<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\ConfigurationError;
use TanoWAF\WAFCore\Http\HeaderFormat as HF;
use TanoWAF\WAFCore\Http\HeaderQuotedSpansFormat as HQSF;
use TanoWAF\WAFCore\Http\HeaderSpec as HS;
use TanoWAF\WAFCore\Http\StructuredField\Parser as StructuredFieldParser;
use TanoWAF\WAFCore\Logger\PrivateLoggerTrait;
use TanoWAF\WAFCore\Stdlib;

/// @todo do we need to implement plugins/subclasses to add support for headers used in well-known protocols such as eg. webdav?
class HeaderParser implements HeaderParserInterface
{
    use LoggerAwareTrait;
    use PrivateLoggerTrait;

    /** @var HS[] */
    protected array $knownHeaders;

    /**
     * @param HeaderSpec[] $customHeadersSpecs
     * @throws \InvalidArgumentException
     * @throws ConfigurationError
     */
    public function __construct(array $customHeadersSpecs = [], LoggerInterface|null $logger = null)
    {
        if (! Stdlib::array_of($customHeadersSpecs, HeaderSpec::class)) {
            throw new \InvalidArgumentException('customHeadersSpec argument to HeaderParser constructor must be an array of HeaderSpec objects');
        }

        $this->knownHeaders = HeaderSpecFactory::getHeadersSpecifications($customHeadersSpecs);
        $this->logger = $logger;
    }

    /**
     * Normalizes the value of a header, as obtained by a psr-7 message, by splitting it into a list of strings and
     * removing excess whitespace and quoted-encodings, based on its known syntax.
     * Custom headers f.e. just get values split on commas and trimmed of space/tab.
     * The format for specific headers can be set via the parser constructor.
     * NB: assumes that the header name and value(s) come from a web-server, meaning that some basic validation has
     * already happened, eg. there are no ctrl characters or \n or \r in its name, or x7F in either name or value
     * (we test that this is true for all supported webservers via BA_ServerRequestCreatorTest tests)
     *
     * @param string $name has to be lowercase
     * @param string[] $values as obtained by a psr-7 message
     * @return string[]
     */
    public function normalizeHeaderValue(string $name, array $values, array|null &$errorsFound = []): array
    {
        return $this->normalizeHeaderValueBySpec($values, $this->knownHeaders[$name] ?? null, $errorsFound);
    }

    /**
     * Checks that a header value is fully compliant with its RFC specification
     * @param string $name has to be lowercase
     * @param string[] $values
     * @return bool true if the header is compliant
     */
    public function validateHeaderValue(string $name, array $values, array|null &$errorsFound = []): bool
    {
        // The following was disabled after checking that the (supported) webservers will not allow such headers
        // to reach php anyway
/// @todo... add more tests specifically for this in BA_ServerRequestCreatorTest - esp. some sending these chars within a known quoted-string header
        /// @todo introduce a version of this that does extra validation, eg. checking for NUL, CR, LF, DEL chars in header name + value,
        ///       and CTRL chars or > 127 in header name
        /// @todo introduce a version of this that does also check for chars !#$%&\'*+.^_`|~ in the header name
        /// @todo would a regexp be faster?
        //foreach ($values as $value) {
        //    if (str_contains("\n", $value) || str_contains("\r", $value)
        //        || str_contains("\x00", $value) || str_contains("\x7F", $value)) {
        //        return false;
        //    }
        //}

        $errorsFound = [];

        if (!array_key_exists($name, $this->knownHeaders)) {
            return true;
        }

        $spec = $this->knownHeaders[$name];

        if ($spec->isSingleton && (count($values) > 1)) {
            $errorsFound = ['Header has two or more values but it is defined as singleton'];
            return false;
        }

/// @todo... for some header types, normalizeHeaderValueBySpec is enough to trigger full validation of the value, whereas
///          for some others this does not happen, for the sake of speed. Should we unify that behaviour?
///          - doing full validation (eg. including a regexp check) when the goal is to extract only a value to match on is indeed wasteful...
///          - ...otoh we could presume the recommended fw config is to have a `wildcard_http_header_rfc_compliant: *` rule as first filter,
///            in which case it is more important to avoid doing the parsing twice (but remember that caching parsed header
///            values and parsing errors results in more memory usage)
///          If otoh ww strive to do the opposite, and reduce the amount of extraction-parsing done by normalizeHeaderValueBySpec
///          for Structured Field headers:
///          - for SF Item headers we could maybe omit doing anything, as they are supposed to be one-value-per-header, and
///            there is little OWS in their spec that needs normalizing (maybe bool value parameters?)
///          - for Structured Field headers of type List and Dictionary we are forced to run the full parsing algorithm
///            in order to be able to find out the commas used to split values on (String items allow commas within their quotes)

        $normalizedValues = $this->normalizeHeaderValueBySpec($values, $spec, $errorsFound);

        if ($errorsFound) {
            return false;
        }

        if ($spec->isSingleton && count($normalizedValues) > 1) {
            $errorsFound = ['Header has two or more values (after normalization) but it is defined as singleton'];
            return false;
        }

        switch ($spec->format) {
            case HF::Cookie:
                // the regex is more stringent than the normalization, and checks the whole header
                foreach ($values as $value) {
                    if (!preg_match($spec->validationRegexp, $value)) {
                        $errorsFound = ['Header does not comply with the cookie format (regex)'];
                        return false;
                    }
                }
                break;
            case HF::Date:
            case HF::Token:
            case HF::Integer:
                foreach ($normalizedValues as $value) {
                    if (!preg_match($spec->validationRegexp, $value)) {
                        $errorsFound = ['Header does not comply with the ' . $spec->format->value . ' format (regex)'];
                        return false;
                    }
                }
                break;
            case HF::Generic:
                if ($spec->validationRegexp !== null) {
                    foreach ($normalizedValues as $value) {
                        if (!preg_match($spec->validationRegexp, $value)) {
                            $errorsFound = ['Header does not comply with its format (regex)'];
                            return false;
                        }
                    }
                }
                break;
/// @todo...
            /*
            case HF::SFDictionary:
            case HF::SFList:
            case HF::SFItem:
            case HF::SFBoolean:
            case HF::SFByteSequence:
            case HF::SFDate:
            case HF::SFDecimal:
            case HF::SFDisplayString:
            case HF::SFInteger:
            case HF::SFString:
            case HF::SFToken:
            */
        }

        return true;
    }

    /**
     * Normalizes the value of a header, as obtained by a psr-7 message, by splitting it into a list of strings and
     * removing quoted-encodings, based on its known syntax.
     *
     * @param string[] $values as obtained by a psr-7 message. Keys must be numerically indexed from 0
     * @return string[]
     *
     * @todo review the choice of using a by-ref array for passing around the error messages
     */
    protected function normalizeHeaderValueBySpec(array $values, HS|null $hs, array|null &$errorsFound = []): array
    {
        //$isToken = $hs !== null && $hs->format === HF::Token;
        $isCookie = $hs !== null && $hs->format === HF::Cookie;
        $isDate = $hs !== null && $hs->format === HF::Date;
        $isJson = $hs !== null && $hs->format === HF::Json;
        /// @todo move this methods to HP/HF?
        $isStructuredDictionary = $hs !== null && $hs->format === HF::SFDictionary;
        $isStructuredList = $hs !== null && $hs->format === HF::SFList;
        // includes both Item and its sub-specs
        $isStructuredItem = $hs !== null && (str_ends_with($hs->format->value, 'Item'));

        $allowsQuotedStrings = $hs !== null && $hs->quotedSpansFormat === HQSF::QuotedString;
        //$allowsComments = $options & HeaderSpec::ALLOWS_TRAILING_COMMENT;

        $splitValuesOnCommas = !($isCookie || $isDate || $isJson || $isStructuredDictionary || $isStructuredList || $isStructuredItem);
        //$joinMultipleValuesOnCommas = false;

        // Which approach is better for headers supposed to be singletons?
        // 1. split on commas and then check how many values we got, or
        // 2. do not split and keep a single-value? Or even
        // 3. combine multiple values with a ', '
        //
        // The constraints are:
        // - the http spec says that for _any header_, _the recipient_ can concatenate the values of multiple header
        //   occurrences without changing the semantics of the message (but we are an intermediary, not the recipient)
        // - the PSR APIs we use to retrieve the header values fed to this method gives us an array as value for each header...
        // - ...but in the end, for the most common scenario (PHP running as a FCGI app, or via a webserver), the
        //   values for http headers are gotten from $_SERVER['HTTP_***'], meaning that for each http header there
        //   will be _only 1 value_, not many. Which, in turn, means that the webserver is extremely likely to
        //   concatenate together multiple values using the comma rule.
        // - the exception to this being Swoole, which does provide us with proper multi-valued headers instead of a single
        //   string for each one (except for `Cookie`, which we handle with custom code in the RequestFactory)
        //
        // This means that, given header "X-Custom", defined as singleton, it is factually impossible for php to tell
        // apart these 2 cases:
        //     X-Custom: "hello
        //     X-Custom: world"
        // and
        //     X-custom: "hello, world"
        // which is of course not the best position to be in for a firewall... Fe. rfc9651 explicitly says that a parser
        // _might_ reject the first case...
        //
        // The best solution seems to be to:
        // - start out with the a-priori knowledge of the headers that should not be split on quotes, rather than the
        //   headers which are supposed to be singletons, and start from that, and
        // - leave full conformance verification to a separate method
        //
        // The consequence of this approach is that this function gives different results depending on whether the layer
        // which populates the header values does the automatic concatenation of duplicate headers with commas or not...
        //
        // See: https://github.com/php/frankenphp/discussions/2575

        //if ($joinMultipleValuesOnCommas) {
        //    $values = [implode(', '), $values];
        //}

        $out = [];
        $errorsFound = [];
        foreach ($values as $value) {

            // @see https://www.rfc-editor.org/info/rfc9112/#section-5
            // @see https://www.rfc-editor.org/info/rfc9110/#section-5.5

            // some webservers (I am looking at you, Nginx...) do not _always apply this stripping
            $value = trim($value, " \t");

/// @todo... allow stripping of trailing comments

            if ($splitValuesOnCommas) {
                if ($allowsQuotedStrings) {
                    $pieces = $this->normalizeMaybeQuotedString($value, $errorsFound);
                } else {
                    // non-singleton, no quoted strings
                    $pieces = $this->normalizeGeneric($value, $errorsFound);
                }
                $out = array_merge($out, $pieces);
            } elseif ($isCookie) {
                $out = array_merge($out, $this->normalizeCookie($value, $errorsFound));
            //} elseif ($isDate) {
            //    $out[] = $this->normalizeDate($value, $errorsFound);
            } elseif ($isStructuredDictionary) {
                $out = array_merge($out, $this->normalizeStructuredDictionary($value, $errorsFound));
            } elseif ($isStructuredList) {
                $out = array_merge($out, $this->normalizeStructuredList($value, $errorsFound));
            } elseif ($isStructuredItem) {
                $out[] = $this->normalizeStructuredItem($value, $hs->format, $errorsFound);
            } elseif ($isJson) {
                $out[] = $this->normalizeJson($value, $errorsFound);
            } else {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * This "normalizes" the Cookie header, by splitting it as it is effectively a list - but it does not use the
     * cookie name as key of the returned array.
     *
     * @return string[]
     */
    protected function normalizeCookie(string $value, array &$errorsFound): array
    {
/// @todo... should we remove dquotes around the value? We do get them in $_COOKIE, but end users might not expect them
/// @todo... should we strip/replace CTLs, whitespace, DQUOTE, comma, semicolon and backslash?
///          Note the test results in duplicateCookieDataProvider: webservers (or, most likely, the php engine) are quite
///          lenient in what they pass on to php in $_COOKIE...)
        $pieces = preg_split("/;[ \\t]*/", $value, -1, PREG_SPLIT_NO_EMPTY);
        return $pieces;
    }

    /*protected function normalizeDate(string $value, array &$errorsFound): string
    {
        return $value;
    }*/

    /**
     * @return string[]
     */
    protected function normalizeGeneric(string $value, array &$errorsFound): array
    {
        return preg_split("/[ \\t]*,[ \\t]*/", $value, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Normalizes a json value by removing (most) unnecessary whitespace, decoding \uXXXX escaping
     *
     * @todo... this normalization has a few downsides, including:
     *          - a received '1.0' will be converted into a '1'
     *          - the amount of whitespace after an object's key in the produced string varies - see BB_HeaderParsingTest
     *
     * @todo... save the parsed Json for later reuse
     */
    protected function normalizeJson(string $value, array &$errorsFound): string
    {
        $parsedValue = @json_decode($value);
        if (json_last_error()) {
            $errorsFound[] = 'Invalid json: ' . json_last_error_msg();
            return $value;
        }
        $output = json_encode($parsedValue,  JSON_INVALID_UTF8_SUBSTITUTE & JSON_PRESERVE_ZERO_FRACTION);
        if ($output === false) {
            $errorsFound[] = 'Could not re-serialize json';
            return $value;
        }
        return $output;
    }

    /**
     * @return string[]
     */
    protected function normalizeMaybeQuotedString(string $value, array &$errorsFound): array
    {
        $pieces = [];

        $len = strlen($value);

        /// @todo can we optimize the handling of $trailingSpaces?

        $piece = '';
        $quoted = false;
        $notStarted = true;
        $trailingSpaces = '';

        for ($i = 0; $i < $len; $i++) {

            if ($notStarted) {
                switch($value[$i]) {
                    case '"':
                        $quoted = true;
                        $notStarted = false;
                        $trailingSpaces = '';
                        continue 2; // advance to next char
                    case ' ':
                    case "\t":
                    case ",":
                        continue 2; // advance to next char
                    default:
                        $piece .= $value[$i];
                        $notStarted = false;
                        $trailingSpaces = '';
                        continue 2; // advance to next char
                }
            }

            if ($quoted) {
                switch($value[$i]) {
                    case '\\':
                        if (($j = $i+1) < $len) {
                            $piece .= $value[$j];
                            $i++;
                        } else {
                            $errorsFound[] = 'Invalid quoted-string: it has backslash before final quote';
/// @todo... should we add the final, spurious backslash to the returned value instead of dropping it?
                            $pieces[] = $piece;
                            $quoted = false;
                            $piece = '';
                        }
                        break;
                    case '"':
                        $quoted = false;
                        // in case the last chars where spaces or tabs, we need to preserve them
                        $trailingSpaces = '';
                        for ($j = strlen($piece) - 1; $j >= 0; $j--) {
                            if ($piece[$j] === ' ' || $piece[$j] === "\t") {
                                $trailingSpaces = $piece[$j] . $trailingSpaces;
                            } else {
                                break;
                            }
                        }
                        break;
                    default:
                        $piece .= $value[$i];
                }
            } else {
                switch($value[$i]) {
                    case ',':
                        // the comma is the separator of multiple header values
                        $pieces[] = rtrim($piece, " \t") . $trailingSpaces;
                        $piece = '';
                        $trailingSpaces = '';
                        $notStarted = true;
                        break;
                    case '"':
                        $quoted = true;
                        break;
                    case ' ':
                    case "\t":
                        $piece .= $value[$i];
                        break;
                    default:
                        if ($trailingSpaces !== '') {
                            $trailingSpaces = '';
                        }
                        $piece .= $value[$i];
                }
            }
        }

        if ($quoted) {
            $errorsFound[] = "Invalid quoted string: missing closing quote";
/// @todo... should we use as returned value the original string, without backslash escaping?
            $pieces[] = $piece;
            $piece = '';
            $notStarted = true;
        }

        if (!$notStarted) {
            $pieces[] = rtrim($piece, " \t") . $trailingSpaces;
        }

        return $pieces;
    }

    /**
     * @return string[]
     * @todo... save the parsed items for later reuse
     */
    protected function normalizeStructuredDictionary($value, array &$errorsFound): array
    {
        $items = StructuredFieldParser::parseDictionary($value, $errorsFound);
        $out = [];
        foreach($items as $name => $item)
        {
/// @todo... fix: for bools, we should omit ?1 and show only the parameters
            $out[] = $name . '=' . $item->__toString();
        }
        return $out;
    }

    protected function normalizeStructuredItem($value, HeaderFormat $hs, array &$errorsFound): string
    {
        /// @todo... save the parsed items for later reuse
        $item = StructuredFieldParser::parseItem($value, $errorsFound);
        if ($item !== null && $hs !== HeaderFormat::SFItem && $hs !== $item->getType()) {
            $errorsFound[] = 'Invalid Structured Field Item found: expected ' . $hs->value . ' but found a ' . $item->getType()->value;
            $item = null;
        }

        return $item === null ? '' : $item->__toString();
    }

    /**
     * @return string[]
     * @todo... save the parsed items for later reuse
     */
    protected function normalizeStructuredList($value, array &$errorsFound): array
    {
        $items = StructuredFieldParser::parseList($value, $errorsFound);
        $out = [];
        foreach($items as $item)
        {
            $out[] = $item->__toString();
        }
        return $out;
    }
}
