<?php

namespace App\Enums;

enum ProtectionLevel: string
{
    case Normal = 'normal';
    case High = 'high';
    case VeryHigh = 'very_high';

    public function rank(): int
    {
        return match ($this) {
            self::Normal => 1, self::High => 2, self::VeryHigh => 3
        };
    }
}
