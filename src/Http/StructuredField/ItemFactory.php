<?php

namespace TanoWAF\WAFCore\Http\StructuredField;

use TanoWAF\WAFCore\Http\HeaderFormat;

class ItemFactory
{
    public static function create($type, $value, array $parameters = []): Item
    {
        switch ($type) {
            case HeaderFormat::SFBoolean:
                return new Item\Boolean($value, $parameters);
            case HeaderFormat::SFByteSequence:
                return new Item\ByteSequence($value, $parameters);
            case HeaderFormat::SFDate:
                return new Item\Date($value, $parameters);
            case HeaderFormat::SFDisplayString:
                return new Item\DisplayString($value, $parameters);
            case HeaderFormat::SFDecimal:
                return new Item\Decimal($value, $parameters);
            case HeaderFormat::SFInteger:
                return new Item\Integer($value, $parameters);
            case HeaderFormat::SFString:
                return new Item\AsciiString($value, $parameters);
            case HeaderFormat::SFToken:
                return new Item\Token($value, $parameters);
            default:
                throw new \InvalidArgumentException("Can not create a Structured Field Item of type " . $type->name);
        }
    }

}
