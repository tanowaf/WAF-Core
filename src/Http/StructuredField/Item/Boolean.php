<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField\Item;

use TanoWAF\WAFCore\Http\StructuredField\Item;
use TanoWAF\WAFCore\Http\StructuredField\ItemTrait;
use TanoWAF\WAFCore\Http\StructuredField\Parameter\Boolean as Base;

class Boolean extends Base implements Item
{
    use ItemTrait;

    public function __construct(bool $value, array $parameters = [])
    {
        parent::__construct($value);
        $this->setParameters($parameters);
    }
}
