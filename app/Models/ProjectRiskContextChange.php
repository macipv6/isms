<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRiskContextChange extends Model
{
    use HasUuids;

    protected $fillable = ['project_risk_context_id', 'project_id', 'action', 'old_methodology_version_id', 'new_methodology_version_id', 'old_catalog_version_id', 'new_catalog_version_id', 'actor_user_id', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ProjectRiskContext, $this> */
    public function context(): BelongsTo
    {
        return $this->belongsTo(ProjectRiskContext::class, 'project_risk_context_id');
    }
}
