<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Filter\Bidirectional;

use TanoWAF\WAFCore\Filter\Request\ClientRequestFilterInterface;
use TanoWAF\WAFCore\Filter\Response\ResponseFilterInterface;

/**
 * A custom take on Psr\Http\Server\MiddlewareInterface.
 * In this case it is a RequestHandler or Middleware running a chain of Filters, instead of the Filters getting the handler injected.
 */
interface ClientBidirectionalFilterInterface extends ClientRequestFilterInterface, ResponseFilterInterface
{
}
