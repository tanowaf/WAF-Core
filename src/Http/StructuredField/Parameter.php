<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField;

use TanoWAF\WAFCore\Http\HeaderFormat;

interface Parameter
{
    public function getType(): HeaderFormat;

    public function getValue(): mixed;
}
