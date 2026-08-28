<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Response\Psr7;

use Psr\Http\Message\ResponseInterface;
use TanoWAF\WAFCore\Http\HeaderParsingCapableInterface;

/**
 * @deprecated not in use any more
 */
interface HeaderParsingCapableResponseInterface extends ResponseInterface, HeaderParsingCapableInterface
{
}
