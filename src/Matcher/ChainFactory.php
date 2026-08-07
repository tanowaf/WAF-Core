<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher;

use TanoWAF\WAFCore\Exception\ConfigurationError;

class ChainFactory implements MatcherFactoryInterface
{
    /** @var MatcherFactoryInterface[] */
    protected array $factories = [];

    /**
     * @param MatcherFactoryInterface[] $factories
     * @throws \InvalidArgumentException
     */
    public function __construct(array $factories)
    {
        if (!$factories) {
            throw new \InvalidArgumentException('Empty list of factories passed to Matcher Chain Factory');
        }
        foreach ($factories as $factory) {
            $this->addFactory($factory);
        }
    }

    public function addFactory(MatcherFactoryInterface $factory)
    {
        $this->factories[] = $factory;
    }

    public function supports(string $type): bool
    {
        foreach ($this->factories as $factory) {
            if ($factory->supports($type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $type
     * @param mixed $values
     * @return MatcherInterface
     * @throws \Exception
     * @todo reduce the scope of possible exceptions thrown
     */
    public function fromConfiguration(string $type, mixed $values): MatcherInterface
    {
        foreach ($this->factories as $factory) {
            if ($factory->supports($type)) {
                return $factory->fromConfiguration($type, $values);
            }
        }
        throw new ConfigurationError("Unsupported matcher type: '$type'");
    }
}
