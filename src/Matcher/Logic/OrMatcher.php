<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Logic;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Matcher\MatcherInterface;
use TanoWAF\WAFCore\Matcher\Request\RequestMatcherInterface;
use TanoWAF\WAFCore\Matcher\Response\ResponseMatcherInterface;

class OrMatcher implements RequestMatcherInterface, ResponseMatcherInterface
{
    /** @var MatcherInterface[] */
    protected array $matchers = [];

    /**
     * @param MatcherInterface[] $matchers
     */
    public function __construct(array $matchers)
    {
        foreach ($matchers as $matcher) {
            $this->addMatcher($matcher);
        }
    }

    public function addMatcher(MatcherInterface $matcher): void
    {
        $this->matchers[] = $matcher;
    }

    /**
     * @param ...$items
     * @return bool
     * @throws \Exception
     */
    public function matches(...$items): bool
    {
        if (!$this->matchers) {
            throw new \Exception('Chain Matcher has no children matchers. Can not test for match');
        }

        foreach ($this->matchers as $matcher) {
            if ($matcher->matches(...$items)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param ServerRequestInterface $request
     * @return bool
     * @throws \Exception
     */
    public function matchesRequest(ServerRequestInterface $request): bool
    {
        return $this->matches($request);
    }

    /**
     * @param ResponseInterface $response
     * @return bool
     * @throws \Exception
     */
    public function matchesResponse(ResponseInterface $response): bool
    {
        return $this->matches($response);
    }
}
