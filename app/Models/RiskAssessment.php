<?php

namespace App\Models;

use App\Enums\RiskAssessmentPhase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'risk_analysis_cycle_id', 'phase', 'revision', 'methodology_version_id', 'frequency_level_id', 'impact_level_id', 'risk_level_id', 'rationale', 'assessed_by', 'assessed_at'];

    protected function casts(): array
    {
        return ['phase' => RiskAssessmentPhase::class, 'revision' => 'integer', 'assessed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<RiskLevel, $this> */
    public function riskLevel(): BelongsTo
    {
        return $this->belongsTo(RiskLevel::class, 'risk_level_id');
    }
}
