<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

enum HeaderQuotedSpansFormat: string
{
    case None = 'none'; // no special handling for spans delimited by double quotes
    case QuotedString = 'qs'; // any char can be escaped with a backslash
    case StructuredField = 'sf'; // only DQ and backslash can be escaped with a backslash
}
