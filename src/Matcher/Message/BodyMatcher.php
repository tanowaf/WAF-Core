<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Http\BodyCompressorTrait;
use TanoWAF\WAFCore\Matcher\RegExpListMatcherTrait;

class BodyMatcher extends BaseMatcher
{
    use RegExpListMatcherTrait;
    use BodyCompressorTrait;

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
/// @todo... move body decompression (and dechunking if needed) to our own Req/resp
        if ($this->messageBodyIsCompressed($message)) {
            $body = $this->decompressMessageBody($message);
        } else {
            $stream = $message->getBody();
            $stream->rewind();
            $body = $stream->getContents();
        }

        return $this->matchesRegexp($body);
    }

    protected function normalizeMatchingRegexp(string $value): string
    {
        return $this->wildcardStringToRegexp($value);
    }
}
