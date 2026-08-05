<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField\Parameter;

use TanoWAF\WAFCore\Http\HeaderFormat;

class Boolean extends Base
{
    public function __construct(bool $value)
    {
        $this->value = $value;
        $this->type = HeaderFormat::SFBoolean;
    }
}
