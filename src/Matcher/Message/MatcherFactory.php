<?php

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\ConfigurationError;
use TanoWAF\WAFCore\Matcher\Logic\AndMatcher;
use TanoWAF\WAFCore\Matcher\MatcherInterface;
use TanoWAF\WAFCore\Matcher\OptionAwareMatcherFactory;
use TanoWAF\WAFCore\Matcher\Response\StatusCodeMatcher;
use TanoWAF\WAFCore\Http\HeaderParserFactory;

/**
 * Used to share code for setting up those matchers that ar identical between request and response
 */
abstract class MatcherFactory extends OptionAwareMatcherFactory
{
    use LoggerAwareTrait;

    protected array $supportedMatcherTypes = [
        'all_http_headers_in',
        'body',
        'content_type',
        'http_header_length_ge',
        'http_header_rfc_compliant',
        'http_header_value',
        'status_code',
        'wildcard_http_header_length_ge',
        'wildcard_http_header_rfc_compliant',
        'wildcard_http_header_value',
    ];

    protected HeaderParserFactory $headerParserFactory;

    public function __construct(HeaderParserFactory $headerParserFactory, LoggerInterface|null $logger = null)
    {
        $this->headerParserFactory = $headerParserFactory;
        $this->logger = $logger;
    }

    /**
     * @throws ConfigurationError|\Throwable
     * @todo reduce the scope of possible exceptions thrown
     */
    public function fromConfiguration(string $type, mixed $values): MatcherInterface
    {
        $matcherType = $this->getMatcherType($type);
        try {
            switch ($matcherType) {
                case 'all_http_headers_in':
                    $opts = $this->parseMatcherBooleanOptions($type, ['no_wildcards' => true]);
                    $matcher = new HeaderNameMatcher($values, $opts['no_wildcards'], true);
                    break;
                case 'body':
                    $opts = $this->parseMatcherBooleanOptions($type, ['case_insensitive' => false, 'no_wildcards' => true]);
                    $matcher = new BodyMatcher($values, $opts['case_insensitive'], $opts['no_wildcards']);
                    break;
                case 'content_type':
                    $opts = $this->parseMatcherBooleanOptions($type, ['no_wildcards' => true]);
                    $matcher = new ContentTypeMatcher($values, $opts['no_wildcards']);
                    break;
                case 'http_header_value':
                case 'wildcard_http_header_value':
                    if (!is_array($values) || !$values) {
                        throw new ConfigurationError("Invalid message matching configuration: '$type' should be followed with an object with 1 or more elements");
                    }
                    $parser = $this->headerParserFactory->createParser();
                    $matchers = [];
                    foreach ($values as $hn => $hv) {
                        if (!is_string($hn) || !(is_string($hv) || is_array($hv))) {
                            throw new ConfigurationError("Invalid response matching configuration: '$type' should be followed with an object with the http header name(s) as keys and string or array of strings for values");
                        }
                        $this->validateHeaderName($hn);
                        $opts = $this->parseMatcherBooleanOptions($type, ['case_insensitive' => false, 'no_wildcards' => true]);
                        $matcher = new HeaderValueMatcher($hn, $hv, $opts['case_insensitive'], $opts['no_wildcards'], str_starts_with($matcherType, 'wildcard_'));
                        $matcher->setHeaderParser($parser);
                        $matchers[] = $matcher;
                    }
                    if (count($matchers) > 1) {
                        $matcher = new AndMatcher($matchers);
                    } else {
                        $matcher = $matchers[0];
                    }
                    break;
                case 'http_header_length_ge':
                case 'wildcard_http_header_length_ge':
                    if (!is_array($values) || !$values) {
                        throw new ConfigurationError("Invalid message matching configuration: '$type' should be followed with an object with 1 or more elements");
                    }
                    $matchers = [];
                    foreach ($values as $hn => $hv) {
                        if (!is_string($hn) || !is_int($hv)) {
                            throw new ConfigurationError("Invalid message matching configuration: '$type' should be followed with an object with the http header name as keys and an int for value");
                        }
                        $this->validateHeaderName($hn);
                        $matchers[] = new HeaderLengthMatcher($hn, $hv, true, str_starts_with($matcherType, 'wildcard_'));
                    }
                    if (count($matchers) > 1) {
                        $matcher = new AndMatcher($matchers);
                    } else {
                        $matcher = $matchers[0];
                    }
                    break;
                case 'http_header_rfc_compliant':
                case 'wildcard_http_header_rfc_compliant':
/// @todo... make sure that HeaderRFCComplianceMatcher validates $values is a string|string[] with at least one element
                    $matcher = new HeaderRFCComplianceMatcher($values, str_starts_with($matcherType, 'wildcard_'));
                    $matcher->setHeaderParser($this->headerParserFactory->createParser());
                    break;
                case 'status_code':
                    $opts = $this->parseMatcherBooleanOptions($type, ['no_wildcards' => true]);
                    $matcher = new StatusCodeMatcher($values, $opts['no_wildcards']);
                    break;
                default:
                    throw new ConfigurationError("Invalid message matching configuration: '$type' => " . var_export($values, true));
            }
        } catch (\Throwable $t) {
            // convert the exceptions thrown by different types of validation failures into ConfigurationErrors
            if ($t instanceof \TypeError) {
                throw new ConfigurationError("Invalid message matching configuration, unexpected type used: " . $t->getMessage());
            }
            if ($t instanceof \InvalidArgumentException) {
                throw new ConfigurationError("Invalid message matching configuration: " . $t->getMessage());
            }
            throw $t;
        }
        if ($this->logger && $matcher instanceof LoggerAwareInterface) {
            $matcher->setLogger($this->logger);
        }
        return $matcher;
    }

    /**
     * @throws ConfigurationError
     * @todo... move to the single matchers constructors?
     */
    protected function validateHeaderName(string $hn): void
    {
        /// @todo improve validation - reject headers with any chars which are not valid in the rfc? Also, move to a shared function
        /// @todo improve validation - reject headers with any chars which are not valid in the rfc?
        if (trim($hn) !== $hn) {
            throw new ConfigurationError("Invalid request matching configuration: header name should not contain whitespace");
        }
    }
}
