<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContinuityPlan extends Model
{
    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['project_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /** @return HasMany<ContinuityPlanRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ContinuityPlanRevision::class);
    }
}
