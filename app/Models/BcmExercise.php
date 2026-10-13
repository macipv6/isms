<?php

namespace App\Models;

use App\Enums\BcmExerciseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BcmExerciseStatus $status
 * @property int $lock_version
 * @property array<string, mixed> $source_snapshot
 */
class BcmExercise extends Model
{
    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['project_id', 'continuity_plan_revision_id', 'status', 'lock_version', 'scenario', 'exercise_type', 'planned_at', 'leader_name', 'participants', 'actual_at', 'source_snapshot', 'created_by', 'completed_by', 'completed_at', 'cancelled_by', 'cancelled_at', 'cancelled_reason', 'source_change_acknowledged_by', 'source_change_acknowledged_at', 'source_change_reason'];

    protected function casts(): array
    {
        return ['status' => BcmExerciseStatus::class, 'lock_version' => 'integer', 'source_snapshot' => 'array', 'planned_at' => 'immutable_datetime', 'actual_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'source_change_acknowledged_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /** @return BelongsTo<ContinuityPlanRevision, $this> */
    public function planRevision(): BelongsTo
    {
        return $this->belongsTo(ContinuityPlanRevision::class, 'continuity_plan_revision_id');
    }

    /** @return HasMany<BcmExerciseCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(BcmExerciseCriterion::class);
    }

    /** @return HasMany<BcmExerciseResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(BcmExerciseResult::class);
    }

    /** @return HasMany<BcmExerciseEvidenceLink, $this> */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(BcmExerciseEvidenceLink::class);
    }
}
