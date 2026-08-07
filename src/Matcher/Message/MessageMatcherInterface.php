<?php

namespace TanoWAF\WAFCore\Matcher\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface MessageMatcherInterface
{
    /**
     * We restrict the supported type to be either a Request or a Response - as there is no other type of message that
     * we know how to handle
     */
    public function matchesMessage(RequestInterface|ResponseInterface $message): bool;
}
