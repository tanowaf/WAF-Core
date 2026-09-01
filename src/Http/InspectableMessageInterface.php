<?php

namespace TanoWAF\WAFCore\Http;

/**
 * This interface is designed to bring together all capabilities that are required from Requests and Responses
 * by Firewall matchers and filters. It is built by composition of interfaces defining the single capabilities.
 *
 * @todo... note that atm we are kind of abusing the ServerRequestInterface methods `getQueryParams` and (eventually)
 *          `getCookieParams` - no other class but our own ServerRequest will use the queryStringParser and cookieParser
 *          to build the values returned by those methods. Should we use instead custom methods and add them here?
 */
interface InspectableMessageInterface extends BodyParsingCapableInterface, BodyUncompressingCapableInterface, HeaderParsingCapableInterface
{
}
