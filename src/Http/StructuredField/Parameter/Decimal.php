<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField\Parameter;

use TanoWAF\WAFCore\Http\HeaderFormat;

class Decimal extends Base
{
    public function __construct(float $value)
    {
        $this->value = $value;
        $this->type = HeaderFormat::SFDecimal;
    }
}
