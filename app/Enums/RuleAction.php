<?php

namespace App\Enums;

enum RuleAction: string
{
    case Include = 'include';
    case Exclude = 'exclude';
}
