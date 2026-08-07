<?php

namespace TanoWAF\WAFCore\Firewall;

enum RuleAction: string
{
    /// @todo: rename 'allow' to 'filter'? That would make the logic for response-matching configs clearer...
    case Allow = 'allow';
    case Deny = 'deny';
    /// @todo
    //case Rerun = 'rerun'; // restart the rule chain, either including or excluding the current rule
    //case Continue = 'continue';
    //case Score = 'score';
}
