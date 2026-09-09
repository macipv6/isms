<?php

namespace App\Enums;

enum FindingSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
