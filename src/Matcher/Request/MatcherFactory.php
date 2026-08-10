<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\ConfigurationError;
use TanoWAF\WAFCore\Matcher\Logic\AndMatcher;
use TanoWAF\WAFCore\Matcher\MatcherFactoryInterface;
use TanoWAF\WAFCore\Matcher\MatcherInterface;
use TanoWAF\WAFCore\Matcher\Message\MatcherFactory as BaseMatcherFactory;

class MatcherFactory extends BaseMatcherFactory implements MatcherFactoryInterface
{
    public function __construct(LoggerInterface|null $logger = null)
    {
        parent::__construct($logger);

        $this->supportedMatcherTypes = array_merge($this->supportedMatcherTypes, [
            'all_query_string_parameters_in',
            'client_address',
            'client_port',
            'content_type',
            'host',
            'http_method',
            'port',
            'protocol_version',
            'query_string_parameter_value',
            'scheme',
            'url_path',
            'user_agent',
            'wildcard_query_string_parameter_value'
        ]);
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
                case 'all_query_string_parameters_in':
                    $opts = $this->parseMatcherBooleanOptions($type, ['no_wildcards' => true]);
                    $matcher = new QueryStringParamNameMatcher($values, $opts['no_wildcards'], true);
                    break;
                /// @todo accept 'request_body' as an alias?
                case 'client_address':
                    $opts = $this->parseMatcherBooleanOptions($type, ['no_wildcards' => true]);
                    $matcher = new ClientAddressMatcher($values, $opts['no_wildcards']);
                    break;
                case 'client_port':
                    $opts = $this->parseMatcherBooleanOptions($type, ['no_wildcards' => true]);
                    $matcher = new ClientPortMatcher($values, $opts['no_wildcards']);
                    break;
                /// @todo accept 'request_content_type' as an alias?
                case 'host':
                    $opts = $this->parseMatcherBooleanOptions($type, ['no_wildcards' => true]);
                    $matcher = new HostMatcher($values, $opts['no_wildcards']);
                    break;
                /// @todo accept 'request_http_header' as an alias?
                /// @todo accept 'method' as an alias?
                case 'http_method':
                    $matcher = new MethodMatcher($values);
                    break;
                case 'port':
                    $opts = $this->parseMatcherBooleanOptions($type, ['no_wildcards' => true]);
                    $matcher = new PortMatcher($values, $opts['no_wildcards']);
                    break;
                case 'protocol_version':
                    $matcher = new ProtocolVersionMatcher($values);
                    break;
                case 'query_string_parameter_value':
                case 'wildcard_query_string_parameter_value':
                    if (!is_array($values) || !($values)) {
                        throw new ConfigurationError("Invalid request matching configuration: '$type' should be followed with an object with 1 element only");
                    }
                    $matchers = [];
                    foreach ($values as $qspn => $qspv) {
                        if (!is_string($qspn) || !(is_string($qspv) || is_array($qspv))) {
                            throw new ConfigurationError("Invalid request matching configuration: '$type' should be followed with an object with the query string parameter name(s) as keys and string or array of strings for values");
                        }
                        $opts = $this->parseMatcherBooleanOptions($type, ['case_insensitive' => false, 'no_wildcards' => true]);
                        $matchers[] = new QueryStringParamValueMatcher($qspn, $qspv, $opts['case_insensitive'], $opts['no_wildcards'], str_starts_with($matcherType, 'wildcard_'));
                    }
                    if (count($matchers) > 1) {
                        $matcher = new AndMatcher($matchers);
                    } else {
                        $matcher = $matchers[0];
                    }
                    break;
                case 'scheme':
                    $opts = $this->parseMatcherBooleanOptions($type, ['no_wildcards' => true]);
                    $matcher = new SchemeMatcher($values, $opts['no_wildcards']);
                    break;
                /// @todo accept 'path' as an alias?
                case 'url_path':
                    $opts = $this->parseMatcherBooleanOptions($type, ['case_insensitive' => false, 'no_wildcards' => true]);
                    $matcher = new PathMatcher($values, '', $opts['case_insensitive'], $opts['no_wildcards']);
                    break;
                case 'user_agent':
                    $opts = $this->parseMatcherBooleanOptions($type, ['case_insensitive' => false, 'no_wildcards' => true]);
                    $matcher = new UserAgentMatcher($values, $opts['case_insensitive'], $opts['no_wildcards']);
                    break;
                default:
                    return parent::fromConfiguration($type, $values);
            }
        } catch (\Throwable $t) {
            // convert the exceptions thrown by different types of validation failures into ConfigurationErrors
            if ($t instanceof \TypeError) {
                throw new ConfigurationError("Invalid request matching configuration, unexpected type used: " . $t->getMessage());
            }
            if ($t instanceof \InvalidArgumentException) {
                throw new ConfigurationError("Invalid request matching configuration: " . $t->getMessage());
            }
            throw $t;
        }
        if ($this->logger && $matcher instanceof LoggerAwareInterface) {
            $matcher->setLogger($this->logger);
        }
        return $matcher;
    }
}
