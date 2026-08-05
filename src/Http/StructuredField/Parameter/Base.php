<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField\Parameter;

use TanoWAF\WAFCore\Http\HeaderFormat;
use TanoWAF\WAFCore\Http\StructuredField\Parameter;

abstract class Base implements Parameter
{
    protected HeaderFormat $type;
    protected mixed $value;

    public function getType(): HeaderFormat
    {
        return $this->type;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function __toString(): string
    {
        switch ($this->type) {
            case HeaderFormat::SFBoolean:
                return $this->value ? '?1' : '?0';
            case HeaderFormat::SFByteSequence:
                return ':' . $this->value . ':';
            case HeaderFormat::SFDate:
                return '@' . $this->value;
            case HeaderFormat::SFDisplayString:
/// @todo... escape all non-ascii chars / fail if any?
                return '%"' . $this->value . '"';
            case HeaderFormat::SFDecimal:
            case HeaderFormat::SFInteger:
/// @todo... check if this always uses ., and its precision
                return (string)$this->value;
            case HeaderFormat::SFString:
/// @todo... escape all non-ascii chars / fail if any
                return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $this->value) . '"';
            case HeaderFormat::SFToken:
                return $this->value;
            default:
                throw new \InvalidArgumentException("Can not serialize an Structured Item / Parameter of type " . $this->type->name);
        }
    }

    public function toString($compactTrue = false): string
    {
        if ($compactTrue && $this->type === HeaderFormat::SFBoolean && $this->value) {
            return '';
        }
        return $this->__toString();
    }
}
