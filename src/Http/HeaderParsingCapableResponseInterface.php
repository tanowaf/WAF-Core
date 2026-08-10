<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

use Psr\Http\Message\ResponseInterface;

interface HeaderParsingCapableResponseInterface extends ResponseInterface, HeaderParsingCapableInterface
{
}
