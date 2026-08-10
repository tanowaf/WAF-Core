<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Firewall;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TanoWAF\WAFCore\Exception\ConfigurationError;
use TanoWAF\WAFCore\Http\HeaderParserFactory;
use TanoWAF\WAFCore\Logger\PrivateLoggerTrait;

class FirewallFactory
{
    use LoggerAwareTrait;
    use PrivateLoggerTrait;

    public function __construct(LoggerInterface|null $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $configurationFile
     * @return Firewall
     * @throws \Exception
     * @todo allow parsing yaml files besides json ones
     */
    public function fromConfigFile(string $configurationFile): Firewall
    {
        $this->info("Loading firewall configuration from file '$configurationFile'");
        if (($configString = @file_get_contents($configurationFile)) === false) {
            throw new ConfigurationError("Can not load configuration file '$configurationFile' " . error_get_last()['message']);
        }
        return $this->fromConfigString($configString);
    }

    /**
     * @param string $configuration
     * @return static
     * @throws \Exception
     */
    public function fromConfigString(string $configuration): Firewall
    {
        //$this->debug("Loading firewall configuration from string");
        if (trim($configuration) === '') {
            $config = [];
        } else {
            $config = @json_decode($configuration, true);
            if (!is_array($config)) {
                throw new ConfigurationError("The configuration passed in is not a valid json array. Error: " . json_last_error_msg());
            }
        }
        return $this->fromConfiguration($config);
    }

    /**
     * @param array $config
     * @return Firewall
     * @throws \Exception
     */
    public function fromConfiguration(array $config): Firewall
    {
        if (!$config) {
            $this->warning("Empty configuration passed in. The firewall will block every request");
        }

        foreach ($config as $ruleName => $ruleSpec) {
            if (!is_array($ruleSpec)) {
                throw new ConfigurationError("Bad configuration: the value for firewall rule '$ruleName' should be an array");
            }
        }

        $ruleFactory = new RuleFactory($this->logger);
        $rules = [];

        /// @todo give warnings for config smells not caught by fromConfiguration

        foreach ($config as $ruleName => $ruleSpec) {
            try {
                $rule = $ruleFactory->fromConfiguration($ruleSpec);
                $rules[$ruleName] = $rule;
            } catch (\Exception $e) {
                throw new ConfigurationError("Error parsing firewall rule '$ruleName': " . $e->getMessage());
            }
        }

        return new Firewall($rules, $this->logger);
    }
}
