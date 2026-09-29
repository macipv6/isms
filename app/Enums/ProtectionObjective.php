<?php

namespace App\Enums;

enum ProtectionObjective: string
{
    case Confidentiality = 'confidentiality';
    case Integrity = 'integrity';
    case Availability = 'availability';
}
