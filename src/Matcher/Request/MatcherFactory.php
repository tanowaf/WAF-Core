<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Request;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\ConfigurationError;
use TanoWAF\WAFCore\Http\HeaderParserFactory;
use TanoWAF\WAFCore\Matcher\MatcherFactoryInterface;
use TanoWAF\WAFCore\Matcher\MatcherInterface;
use TanoWAF\WAFCore\Matcher\Message\MatcherFactory as BaseMatcherFactory;

class MatcherFactory extends BaseMatcherFactory implements MatcherFactoryInterface
{
    public function __construct(HeaderParserFactory $headerParserFactory, LoggerInterface|null $logger = null)
    {
        parent::__construct($headerParserFactory, $logger);

        $this->supportedMatcherTypes = array_merge($this->supportedMatcherTypes, [
            'client_address',
            'client_port',
            'content_type',
            'host',
            'http_method',
            'port',
            'protocol_version',
            'query_string',
            'scheme',
            'url_path',
            'user_agent',
            'wildcard_query_string'
        ]);
    }

    /**
     * @throws \Exception
     */
    public function fromConfiguration(string $type, mixed $values): MatcherInterface
    {
        $matcherType = $this->getMatcherType($type);
        switch ($matcherType) {
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
/// @todo rename?
            case 'query_string':
            case 'wildcard_query_string':
                if (!is_array($values) || count($values) !== 1) {
                    throw new ConfigurationError("Invalid request matching configuration: '$type' should be followed with an object with 1 element only");
                }
                $qsv = reset($values);
                $qsn = array_key_first($values);
                if (!is_string($qsn) || !(is_string($qsv) || is_array($qsv))) {
                    throw new ConfigurationError("Invalid request matching configuration: '$type' should be followed with an object with 1 element: a string name, and a string or string[] for values");
                }
                $opts = $this->parseMatcherBooleanOptions($type, ['case_insensitive' => false, 'no_wildcards' => true]);
                $matcher = new QueryStringMatcher($qsn, $qsv, $opts['case_insensitive'], $opts['no_wildcards'], str_starts_with($matcherType, 'wildcard_'));
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
                //throw new ConfigurationError("Invalid request matching configuration: '$type' => " . var_export($values, true));
                return parent::fromConfiguration($type, $values);
        }
        if ($this->logger && $matcher instanceof LoggerAwareInterface) {
            $matcher->setLogger($this->logger);
        }
        return $matcher;
    }
}
