<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessImpactAnalysis extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'business_process_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BusinessProcess, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'business_process_id');
    }

    /** @return HasMany<BiaRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(BiaRevision::class);
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }
}
