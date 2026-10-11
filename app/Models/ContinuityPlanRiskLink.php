<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContinuityPlanRiskLink extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'continuity_plan_revision_id', 'risk_analysis_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ContinuityPlanRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(ContinuityPlanRevision::class, 'continuity_plan_revision_id');
    }

    /** @return BelongsTo<RiskAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(RiskAnalysis::class, 'risk_analysis_id');
    }
}
