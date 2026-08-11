<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Response\Psr7;

use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Http\HeaderParsingCapableInterface;

interface HeaderParsingCapableResponseInterface extends ResponseInterface, HeaderParsingCapableInterface
{
}
