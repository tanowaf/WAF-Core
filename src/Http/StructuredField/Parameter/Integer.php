<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField\Parameter;

use TanoWAF\WAFCore\Http\HeaderFormat;

class Integer extends Base
{
    public function __construct(int $value)
    {
        $this->value = $value;
        $this->type = HeaderFormat::SFInteger;
    }
}
