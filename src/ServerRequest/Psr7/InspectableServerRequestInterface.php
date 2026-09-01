<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\ServerRequest\Psr7;

use Psr\Http\Message\ServerRequestInterface;
use TanoWAF\WAFCore\Http\InspectableMessageInterface;

interface InspectableServerRequestInterface extends ServerRequestInterface, InspectableMessageInterface
{
}
