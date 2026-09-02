<?php

namespace App\Enums;

enum AssessmentStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
