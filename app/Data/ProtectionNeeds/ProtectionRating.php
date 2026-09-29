<?php

namespace App\Data\ProtectionNeeds;

use App\Enums\ProtectionLevel;

readonly class ProtectionRating
{
    public function __construct(public ProtectionLevel $level, public string $rationale) {}
}
