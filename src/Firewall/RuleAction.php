<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Firewall;

enum RuleAction: string
{
    /// @todo: rename 'allow' to 'filter'? That would make the logic for response-matching configs clearer...
    case Allow = 'allow';
    case Deny = 'deny';
    /// @todo
    //case Rerun = 'rerun'; // restart the rule chain, either including or excluding the current rule
    //case Continue = 'continue'; // nothing to see here, move along
    //case Score = 'score'; // modify the Score of the Request
}
