<?php

namespace App\Enums;

enum MeasureStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
