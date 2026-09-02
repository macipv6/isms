<?php

namespace App\Enums;

enum RuleOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
}
