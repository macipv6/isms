<?php

namespace App\Enums;

enum RiskDecisionStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
