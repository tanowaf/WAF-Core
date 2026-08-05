<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Http;

enum HeaderFormat: string
{
    case Cookie = 'cookie';
    case Date = 'date';
    case Generic = 'generic';
    case Integer = 'integer';
    case Json = 'json';
    case SFItem = 'Item';
    case SFList = 'List';
    case SFDictionary = 'Dictionary';
    case Token = 'token';

    /// @todo these belong logically to the StructuredField, but in php it is not possible to extend enums; also,
    ///       having a single 'format' property in the HeaderSpec simplifies the logic compares to storing these
    ///       'subformats' in a separate property. So we keep them here, at least for the moment...
    case SFBoolean = 'BooleanItem';
    case SFByteSequence = 'ByteSequenceItem';
    case SFDate = 'DateItem';
    case SFDisplayString = 'DisplayStringItem';
    case SFDecimal = 'DecimalItem';
    case SFInteger = 'IntegerItem';
    case SFString = 'StringItem';
    case SFToken = 'TokenItem';
}
