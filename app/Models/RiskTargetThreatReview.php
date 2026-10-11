<?php

namespace App\Models;

use App\Enums\ThreatReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property ThreatReviewStatus $status */
class RiskTargetThreatReview extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'business_process_id', 'asset_id', 'project_threat_id', 'status', 'rationale', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['status' => ThreatReviewStatus::class, 'reviewed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ProjectThreat, $this> */
    public function threat(): BelongsTo
    {
        return $this->belongsTo(ProjectThreat::class, 'project_threat_id');
    }
}
