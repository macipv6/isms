<?php

namespace App\Enums;

enum FindingOrigin: string
{
    case AssessmentQuestion = 'assessment_question';
    case BcmExerciseResult = 'bcm_exercise_result';
}
