<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http\StructuredField;

use TanoWAF\WAFCore\Http\HeaderFormat;
use TanoWAF\WAFCore\Stdlib;

trait ItemTrait
{
    /** @var Parameter[]  */
    protected array $parameters = [];

    /**
     * @return Parameter[]
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    protected function setParameters(array $parameters = []): void
    {
        if (!Stdlib::array_of($parameters, Parameter::class)) {
            throw new \InvalidArgumentException("Item parameters should be Parameter instances");
        }
        $this->parameters = $parameters;
    }

    public static function create($type, $value, array $parameters = [])
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
                throw new \InvalidArgumentException("Can not create a Structured Item / Parameter of type " . $type->name);
        }
    }

    public function __toString(): string
    {
        $out = parent::__toString();
        if ($this->parameters) {
            $pieces = [];
            foreach ($this->parameters as $name => $parameter)
            {
                if ($parameter->getType() === HeaderFormat::SFBoolean && $parameter->getValue()) {
                    $pieces[] = $name;
                } else {
                    $pieces[] = $name . '=' . $parameter->__toString();
                }
            }
            $out .= ';' . implode(';', $pieces);
        }
        return $out;
    }
}
