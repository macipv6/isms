<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectRiskContext extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'methodology_version_id', 'catalog_version_id', 'initialized_by', 'initialized_at'];

    protected function casts(): array
    {
        return ['initialized_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class, 'project_id');
    }

    /** @return HasMany<ProjectThreat, $this> */
    public function projectThreats(): HasMany
    {
        return $this->hasMany(ProjectThreat::class, 'project_risk_context_id')->orderBy('sort_order');
    }

    /** @return HasMany<ProjectRiskContextChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(ProjectRiskContextChange::class, 'project_risk_context_id');
    }
}
