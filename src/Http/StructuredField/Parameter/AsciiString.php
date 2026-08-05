<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField\Parameter;

use TanoWAF\WAFCore\Http\HeaderFormat;

class AsciiString extends Base
{
    public function __construct(string $value)
    {
        $this->value = $value;
        $this->type = HeaderFormat::SFString;
    }
}
