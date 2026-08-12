<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Firewall;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\ConfigurationError;
use TanoWAF\WAFCore\Logger\PrivateLoggerTrait;
use TanoWAF\WAFCore\Matcher\ChainFactory;
use TanoWAF\WAFCore\Matcher\Logic\AndMatcher;
use TanoWAF\WAFCore\Matcher\Logic\MatcherFactory as LogicMatcherFactory;
use TanoWAF\WAFCore\Matcher\MatcherFactoryInterface;
use TanoWAF\WAFCore\Matcher\Request\MatcherFactory as RequestMatcherFactory;
use TanoWAF\WAFCore\Matcher\Request\RequestMatcherInterface;
use TanoWAF\WAFCore\Matcher\Response\MatcherFactory as ResponseMatcherFactory;
use TanoWAF\WAFCore\Matcher\Response\ResponseMatcherInterface;

class RuleFactory
{
    use LoggerAwareTrait;
    use PrivateLoggerTrait;

    protected MatcherFactoryInterface|null $requestMatcherFactory = null;
    protected MatcherFactoryInterface|null $responseMatcherFactory = null;

    public function __construct(LoggerInterface|null $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @param array $config
     * @return Rule
     * @throws \Exception
     */
    public function fromConfiguration(array $config): Rule
    {
        if (!$config) {
            throw new ConfigurationError("The value should not be an empty array");
        }

        // Allow 'simplified' configuration
        if (!array_key_exists('req_match', $config) && !array_key_exists('req_action', $config) && !array_key_exists('req_filters', $config)
            && !array_key_exists('resp_match', $config) && !array_key_exists('resp_action', $config) && !array_key_exists('resp_filters', $config)) {
            $config = ['req_match' => $config, 'req_action' => RuleAction::Allow->value];
        }

        if ($badKeys = array_diff(array_keys($config), ['req_match', 'req_action', 'req_filters', 'resp_match', 'resp_action', 'resp_filters'])) {
            throw new ConfigurationError("Unsupported keys: " . implode(',', $badKeys));
        }

        // *** Here Be Dragons ***
        // The code below here is way too complicated for its own good... It definitely needs complete test coverage!

        $config = $config + [
            'req_match' => [],
            'req_filters' => [],
            'resp_match' => [],
            'resp_filters' => []
        ];

        if (!is_array($config['req_match']) || !is_array($config['req_filters']) || !is_array($config['resp_match']) ||
            !is_array($config['resp_filters'])) {
            throw new ConfigurationError("req_match, req_filters, resp_match and resp_filters should be arrays");
        }

        if (array_key_exists('req_action', $config)) {
            $requestAction = RuleAction::tryFrom($config['req_action']);
            if ($requestAction === null) {
                throw new ConfigurationError("Unsupported value for req_action '{$config['req_action']}'");
            }
        } else {
            $requestAction = RuleAction::Allow;
        }

        if ($requestAction === RuleAction::Deny && (!$config['req_match'] || $config['req_filters'] ||
                $config['resp_match'] || $config['resp_filters'] || array_key_exists('resp_action', $config))) {
            throw new ConfigurationError("When req_action is deny there can be no req_filters, resp_filters, resp_match or a resp_action, and there has to be a req_match");
        }
        if ($requestAction === RuleAction::Allow && (!$config['req_match'])) {
            if (!$config['resp_match'] && !$config['resp_filters']) {
                throw new ConfigurationError("When req_action is allow there have to be some req_match condition");
            } else {
                $config['req_match'] = ['always' => true];
            }
        }

        if (array_key_exists('resp_action', $config)) {
            $responseAction = RuleAction::tryFrom($config['resp_action']);
            if ($requestAction === null) {
                throw new ConfigurationError("Unsupported value for resp_action '{$config['resp_action']}'");
            }
        } else {
            $responseAction = RuleAction::Allow;
            if (!$config['resp_match'] && !$config['resp_filters']) {
                $config['resp_match'] = ['never' => true];
            }
        }

        if ($responseAction === RuleAction::Deny && ($config['resp_filters'] || !$config['resp_match'])) {
            throw new ConfigurationError("When resp_action is deny there can be no resp_filters and there has to be a resp_match");
        }
        if ($responseAction === RuleAction::Allow && (!$config['resp_match'] || (!$config['resp_filters'] && $config['resp_match'] !== ['never' => true]))) {
            throw new ConfigurationError("When resp_action is allow there have to be some resp_match condition and resp_filters");
        }

        $requestMatcherFactory = $this->getRequestMatcherFactory([]);
        $responseMatcherFactory = $this->getResponseMatcherFactory([]);

        $requestMatcher = $this->parseMatcherConfiguration($config['req_match'], $requestMatcherFactory);
        $requestFilters = $this->parseRequestFiltersConfiguration($config['req_filters']);
        if ($responseAction === RuleAction::Allow && $config['resp_match'] === ['never' => true]) {
            // This is a 'do not mess with the response' configuration, which we try to implement as fast as possible.
            $responseMatcher = null;
            $responseFilters = [];
        } else {
            $responseMatcher = $this->parseMatcherConfiguration($config['resp_match'], $responseMatcherFactory);
            $responseFilters = $this->parseResponseFiltersConfiguration($config['resp_filters']);
        }

        $rule = new Rule(
            $requestMatcher,
            $requestFilters,
            $requestAction,
            $responseMatcher,
            $responseFilters,
            $responseAction
        );
        if ($this->logger && $rule instanceof LoggerAwareInterface) {
            $rule->setLogger($this->logger);
        }
        return $rule;
    }

    /**
     * @throws \Exception
     */
    protected function parseMatcherConfiguration(array $matcherSpec, MatcherFactoryInterface $matcherFactory): RequestMatcherInterface|ResponseMatcherInterface
    {
        if (!$matcherSpec) {
            throw new ConfigurationError("The value for each rule 'match' section must be a non-empty array of conditions");
        }

        if (count($matcherSpec) === 1) {
            $matcher = $matcherFactory->fromConfiguration(array_key_first($matcherSpec), reset($matcherSpec));
        } else {
            $matcher = new AndMatcher([]);
            foreach ($matcherSpec as $type => $values) {
                $matcher->addMatcher($matcherFactory->fromConfiguration((string)$type, $values));
            }
        }
        return $matcher;
    }

    protected function parseRequestFiltersConfiguration(array $filtersSpec): array
    {
/// @todo...
        return [];
    }

    protected function parseResponseFiltersConfiguration(array $filtersSpec): array
    {
/// @todo...
        return [];
    }

    /**
     * @param array $config
     * @return MatcherFactoryInterface
     * @throws \Exception
     */
    protected function getRequestMatcherFactory(array $config): MatcherFactoryInterface
    {
        if ($this->requestMatcherFactory === null) {
            $logicMatcherFactory = new LogicMatcherFactory($this->logger);
            $this->requestMatcherFactory = new ChainFactory([new RequestMatcherFactory($this->logger), $logicMatcherFactory]);
            // inception! ;-)
            $logicMatcherFactory->setMatcherFactory($this->requestMatcherFactory);
        }
        return $this->requestMatcherFactory;
    }

    /**
     * @param array $config
     * @return MatcherFactoryInterface
     * @throws \Exception
     */
    protected function getResponseMatcherFactory(array $config): MatcherFactoryInterface
    {
        if ($this->responseMatcherFactory === null) {
            $logicMatcherFactory = new LogicMatcherFactory($this->logger);
            $this->responseMatcherFactory = new ChainFactory([new ResponseMatcherFactory($this->logger), $logicMatcherFactory]);
            // inception! ;-)
            $logicMatcherFactory->setMatcherFactory($this->responseMatcherFactory);
        }
        return $this->responseMatcherFactory;
    }
}
