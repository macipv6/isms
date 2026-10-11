<?php

namespace App\Enums;

enum BcmExerciseStatus: string
{
    case Draft = 'draft';
    case Planned = 'planned';
    case Conducted = 'conducted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
