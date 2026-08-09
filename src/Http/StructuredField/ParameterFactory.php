<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField;

use TanoWAF\WAFCore\Http\HeaderFormat;

class ParameterFactory
{
    public static function create($type, $value): Parameter
    {
        switch ($type) {
            case HeaderFormat::SFBoolean:
                return new Parameter\Boolean($value);
            case HeaderFormat::SFByteSequence:
                return new Parameter\ByteSequence($value);
            case HeaderFormat::SFDate:
                return new Parameter\Date($value);
            case HeaderFormat::SFDisplayString:
                return new Parameter\DisplayString($value);
            case HeaderFormat::SFDecimal:
                return new Parameter\Decimal($value);
            case HeaderFormat::SFInteger:
                return new Parameter\Integer($value);
            case HeaderFormat::SFString:
                return new Parameter\AsciiString($value);
            case HeaderFormat::SFToken:
                return new Parameter\Token($value);
            default:
                throw new \InvalidArgumentException("Can not create a Structured Field Parameter of type " . $type->name);
        }
    }

}
