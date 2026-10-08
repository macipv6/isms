<?php

namespace App\Models;

use App\Enums\RiskDecisionChannel;
use App\Enums\RiskDecisionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RiskDecision extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'risk_analysis_cycle_id', 'status', 'channel', 'rationale', 'decision_date', 'recorded_by', 'internal_decision_maker_id', 'external_decision_maker_name', 'external_decision_maker_function', 'invalidated_at', 'invalidated_by', 'invalidation_reason'];

    protected function casts(): array
    {
        return ['status' => RiskDecisionStatus::class, 'channel' => RiskDecisionChannel::class, 'decision_date' => 'immutable_date', 'invalidated_at' => 'immutable_datetime'];
    }
}
