<?php

namespace App\Enums;

enum RiskTriggerStatus: string
{
    case Open = 'open';
    case Covered = 'covered';
    case Superseded = 'superseded';
}
