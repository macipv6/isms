<?php

namespace App\Enums;

enum BiaImpactArea: string
{
    case Financial = 'financial';
    case Operational = 'operational';
    case Legal = 'legal';
    case Reputation = 'reputation';
}
