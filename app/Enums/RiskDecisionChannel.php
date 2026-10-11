<?php

namespace App\Enums;

enum RiskDecisionChannel: string
{
    case Internal = 'internal';
    case External = 'external';
}
