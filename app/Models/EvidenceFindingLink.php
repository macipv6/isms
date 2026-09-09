<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EvidenceFindingLink extends Pivot
{
    use HasUuids;
}
