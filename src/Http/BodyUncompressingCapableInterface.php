<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

interface BodyUncompressingCapableInterface
{
    /// @todo... this should most likely return a Stream
    public function getUncompressedMessageBody(): string|null;

    /// @todo... this should most likely return a Stream
    public function withTranscompressedMessageBody(array $acceptedEncodings): self;
}
