<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField;

/// @todo... add types, getter/setters to enforce them
use TanoWAF\WAFCore\Http\HeaderFormat;
use TanoWAF\WAFCore\Stdlib;

interface Item extends Parameter
{
    /** @return Parameter[] */
    public function getParameters(): array;
}
