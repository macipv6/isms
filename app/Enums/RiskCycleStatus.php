<?php

namespace App\Enums;

enum RiskCycleStatus: string
{
    case Draft = 'draft';
    case InherentAssessed = 'inherent_assessed';
    case TreatmentInProgress = 'treatment_in_progress';
    case ResidualAssessed = 'residual_assessed';
    case AwaitingDecision = 'awaiting_decision';
    case Completed = 'completed';
    case Superseded = 'superseded';
}
