<?php

namespace App\Enums;

enum RiskTriggerType: string
{
    case ElevatedProtectionNeed = 'elevated_protection_need';
    case InsufficientBaselineCoverage = 'insufficient_baseline_coverage';
    case NonstandardScenario = 'nonstandard_scenario';
    case Manual = 'manual';

    public function mandatory(): bool
    {
        return $this !== self::Manual;
    }
}
