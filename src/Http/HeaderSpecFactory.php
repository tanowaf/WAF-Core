<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

use TanoWAF\WAFCore\Exception\ConfigurationError;

class HeaderSpecFactory
{
    const HEADERS_SPEC_CONFIG_FILE = __DIR__ . '/../../config/HttpHeadersSpec.json';

    protected static array|null $knownHeaders = null;

    /**
     * @throws ConfigurationError;
     */
    public function fromConfiguration(array $config): HeaderSpec
    {
        if ($extras = array_diff(array_keys($config),
            ['format', 'regex', 'quoted_string_format', 'singleton', 'in_request', 'in_response', 'source', 'comments']))
        {
            throw new ConfigurationError("Unsupported elements found in header specification: " . implode(', ', $extras));
        }

        if (!array_key_exists('format', $config)) {
            throw new ConfigurationError("The 'format' element is mandatory in header specification");
        }
        $format = ($config['format'] === '' || $config['format'] === null) ? 'generic' : $config['format'];
        $format = HeaderFormat::tryFrom($format);
        if ($format === null) {
            throw new ConfigurationError("Unknown header format: '{$config['format']}'");
        }

        if (array_key_exists('quoted_string_format', $config)) {
            $qsf = ($config['quoted_string_format'] === '' || $config['quoted_string_format'] === null) ? 'none' : $config['quoted_string_format'];
            $qsf = HeaderQuotedSpansFormat::tryFrom($qsf);
            if ($qsf === null) {
                throw new ConfigurationError("Unknown quoted string format: '{$config['quoted_string_format']}'");
            }
        } else {
            $qsf = HeaderQuotedSpansFormat::None;
        }

        try {
            return new HeaderSpec(
                $format,
                array_key_exists('regex', $config) ? $config['regex']: null,
                $qsf,
                array_key_exists('singleton', $config) ? $config['singleton']: false,
                array_key_exists('in_request', $config) ? $config['in_request']: true,
                array_key_exists('in_response', $config) ? $config['in_response']: true,
            );
        } catch (\Throwable $e) {
            throw new ConfigurationError($e->getMessage());
        }
    }

    /**
     * Returns the list of known headers specs (stored in a json config file) merged with a custom list passed in
     *
     * @param HeaderSpec[] $customHeadersSpecs
     * @return HeaderSpec[]
     * @throws ConfigurationError
     */
    public static function getHeadersSpecifications(array $customHeadersSpecs = []): array
    {
        if (static::$knownHeaders === null) {
            $configs = json_decode(file_get_contents(self::HEADERS_SPEC_CONFIG_FILE), true);
            if (!is_array($configs)) {
                throw new ConfigurationError("Headers Specifications file '" . self::HEADERS_SPEC_CONFIG_FILE . "' is not a valid json array");
            }
            $factory = new static();
            $specs = [];
            foreach ($configs as $headerName => $spec) {
                if (!preg_match(HeaderSpec::VALIDATION_REGEXPS['token'], $headerName)) {
                    throw new ConfigurationError("'$headerName' is not a valid http header name");
                }
                if (!is_array($spec) && $spec !== null) {
                    throw new ConfigurationError("The specification for header '$headerName' should be an array");
                }
                if ($spec === null || $spec === []) {
                    continue;
                }
                if (!array_diff_key($spec, ['source' => true, 'comments' => true])) {
                    continue;
                }
                $specObject = $factory->fromConfiguration($spec);
                if (static::isNotGeneric($specObject)) {
                    $specs[$headerName] = $specObject;
                }
            }

            static::$knownHeaders = $specs;
        }

        return array_filter($customHeadersSpecs, [static::class, 'isNotGeneric']) + static::$knownHeaders;
    }

    /**
     * Generic headers are the ones which:
     * - have no known format (any sequence of 1 or more chars allowed in http fields are valid)
     * - have no provision for allowing inclusions of the comma character in their value - the comma is used to split them in a list of values
     * - have no provision for using specific escaping rules for spans of texts surrounded by double-quotes (such as the quoted-string rule of rfc9110)
     * - are not restricted to being present only once per message (singletons)
     * - are not restricted to be present only in requests or only in responses
     */
    public static function isNotGeneric(HeaderSpec|null $spec): bool
    {
        if ($spec === null) {
            return false;
        }
        return $spec->format !== HeaderFormat::Generic || $spec->validationRegexp !== null || $spec->quotedSpansFormat !== HeaderQuotedSpansFormat::None ||
            $spec->isSingleton || !$spec->allowedInRequest || !$spec->allowedInResponse;
    }
}
