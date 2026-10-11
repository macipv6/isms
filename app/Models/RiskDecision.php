<?php

namespace App\Models;

use App\Enums\RiskDecisionChannel;
use App\Enums\RiskDecisionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RiskDecisionStatus $status
 * @property RiskDecisionChannel $channel
 */
class RiskDecision extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'risk_analysis_cycle_id', 'residual_assessment_id', 'assessment_phase', 'status', 'channel', 'rationale', 'decision_date', 'recorded_by', 'internal_decision_maker_id', 'external_decision_maker_name', 'external_decision_maker_function', 'invalidated_at', 'invalidated_by', 'invalidation_reason'];

    protected function casts(): array
    {
        return ['status' => RiskDecisionStatus::class, 'channel' => RiskDecisionChannel::class, 'decision_date' => 'immutable_date', 'invalidated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<RiskAssessment, $this> */
    public function residualAssessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'residual_assessment_id');
    }
}
