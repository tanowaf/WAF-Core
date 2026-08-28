<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr7;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Http\BodyUncompressingCapableInterface;
use TanoWAF\WAFCore\Http\HeaderParsingCapableInterface;

/// @todo... rename and extend: we should add in as well BodyUncompressingCapableInterface for uniformity
interface HeaderParsingCapableServerRequestInterface extends ServerRequestInterface, HeaderParsingCapableInterface
{
}
