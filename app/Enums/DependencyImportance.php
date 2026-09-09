<?php

namespace App\Enums;

enum DependencyImportance: string
{
    case Critical = 'critical';
    case Supporting = 'supporting';
}
