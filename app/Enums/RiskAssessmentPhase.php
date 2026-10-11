<?php

namespace App\Enums;

enum RiskAssessmentPhase: string
{
    case Inherent = 'inherent';
    case Residual = 'residual';
}
