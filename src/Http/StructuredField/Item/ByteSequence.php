<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField\Item;

use TanoWAF\WAFCore\Http\StructuredField\Item;
use TanoWAF\WAFCore\Http\StructuredField\ItemTrait;
use TanoWAF\WAFCore\Http\StructuredField\Parameter\ByteSequence as Base;

class ByteSequence extends Base implements Item
{
    use ItemTrait;

    public function __construct(string $value, array $parameters = [])
    {
        parent::__construct($value);
        $this->setParameters($parameters);
    }
}
