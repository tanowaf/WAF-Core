<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Http\BodyCompressorTrait;
use TanoWAF\WAFCore\Http\BodyUncompressingCapableInterface;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class BodyMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;

    /**
     * @param string|string[] $filter
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $filter, bool $caseInsensitive = false, bool $expandWildcards = true)
    {
        $this->expandWildcards = $expandWildcards;
        $this->setMatchingValues($filter, $caseInsensitive);
    }

    /**
     * @throws \TanoWAF\WAFCore\Exception\RequestBodyCantBeDecompressed
     * @throws \TanoWAF\WAFCore\Exception\ResponseBodyCantBeDecompressed
     */
    public function matchesMessage(RequestInterface|ResponseInterface $message): bool
    {
        if (!$message instanceof BodyUncompressingCapableInterface) {
            throw new \Exception("BodyMatcher needs a BodyUncompressingCapableInterface message to operate on, gotten a " . get_class($message));
        }

        return $this->matchesRegexp($message->getUncompressedMessageBody());
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
