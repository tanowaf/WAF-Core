<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

/**
 * A data-class used to hold all the information required for successful parsing and validation of http headers with a known format.
 * Used by the HeaderParser.
 */
class HeaderSpec
{
    /// @todo is it useful to add a constant for other common abnf definitions, such as 'parameter', OWS, .etc..?
    const TOKEN_REGEXP = '[0-9A-Za-z!#$%&\'*+\\-.^_`|~]+';
    const COOKIE_VALUE_REGEXP = '[0-9A-Za-z!#$%&\'*+\\-.^_`|~]+';

    /// @todo... allow specifying other types of double-quoted spans which impact split on commas but have different escaping rules
    //const DOUBLE_QUOTED_ESCAPED = 1;
    //const BACKLASH_AND_DQ_ESCAPED = 2;

    /// @todo... allow specifying the presence of trailing comments, possibly parameters?
    // const ALLOWS_TRAILING_COMMENT = 16384;

    public headerFormat $format;
    public string|null $validationRegexp;
    public bool $isSingleton;
    public HeaderQuotedSpansFormat $quotedSpansFormat;
    public bool $allowedInRequest;
    public bool $allowedInResponse;

/// @todo... should we relax the strict spacing requirements and replace them with OWS?
///          Making this eg. a static var could allow easy modification...
    const VALIDATION_REGEXPS = [
        /// @see https://www.rfc-editor.org/info/rfc6265/#section-4.2
        // NB: this is stricter than what PHP accepts as valid when populating $_COOKIE (eg. it rejects multiple spaces to separate cookies)
        'cookie' => '/^' . self::TOKEN_REGEXP . '=(?:' . self::COOKIE_VALUE_REGEXP . '|"' . self::COOKIE_VALUE_REGEXP . '")(?:; ' . self::TOKEN_REGEXP. '=(?:' . self::COOKIE_VALUE_REGEXP . '|"' . self::COOKIE_VALUE_REGEXP . '"))*$/',
        // @see https://httpwg.org/specs/rfc9110.html#http.date
        // NB: this does not guarantee valid days or times - day 32, hour 25 and minute 99 are all accepted
        'date' => '/^(:?' .
            '(:?' . '(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}:\d{2} GMT' . ')|' .
            '(:?' . '(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday), \d{2}-(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)-\d{2} \d{2}:\d{2}:\d{2} GMT' . ')|' .
            '(:?' . '(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun) (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (?:\d{2}| \d) \d{2}:\d{2}:\d{2} \d{4}' . '))$/',
        'integer' => '/^\d+$/',
        'token' => '/^' . self::TOKEN_REGEXP . '$/',
    ];

    public function __construct(headerFormat $format, string|null $validationRegexp = null, HeaderQuotedSpansFormat $quotedSpansFormat = HeaderQuotedSpansFormat::None, bool $isSingleton = false, bool $allowedInRequest = true, bool $allowedInResponse = true)
    {
        $this->format = $format;
        if ($validationRegexp === null && array_key_exists($format->value, self::VALIDATION_REGEXPS)) {
            $this->validationRegexp = self::VALIDATION_REGEXPS[$format->value];
        } else {
            $this->validationRegexp = $validationRegexp;
        }
        $this->quotedSpansFormat = $quotedSpansFormat;
        $this->isSingleton = $isSingleton;
        $this->allowedInRequest = $allowedInRequest;
        $this->allowedInResponse = $allowedInResponse;
    }
}
