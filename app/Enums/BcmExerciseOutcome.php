<?php

namespace App\Enums;

enum BcmExerciseOutcome: string
{
    case Passed = 'passed';
    case Partial = 'partial';
    case Failed = 'failed';
    case NotTested = 'not_tested';
}
