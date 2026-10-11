<?php

namespace App\Enums;

enum RegisterImportKind: string
{
    case Processes = 'processes';
    case Assets = 'assets';
    case Dependencies = 'dependencies';
    case ProtectionNeeds = 'protection_needs';
}
