<?php

namespace App\Models;

use App\Enums\BcmExerciseOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BcmExerciseOutcome|null $outcome
 * @property int|null $actual_minutes
 */
class BcmExerciseResult extends Model
{
    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['project_id', 'bcm_exercise_id', 'bcm_exercise_criterion_id', 'outcome', 'observations', 'reason', 'actual_minutes', 'finding_waiver_reason', 'finding_waived_by', 'finding_waived_at'];

    protected function casts(): array
    {
        return ['outcome' => BcmExerciseOutcome::class, 'actual_minutes' => 'integer', 'finding_waived_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /** @return BelongsTo<BcmExercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(BcmExercise::class, 'bcm_exercise_id');
    }

    /** @return BelongsTo<BcmExerciseCriterion, $this> */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(BcmExerciseCriterion::class, 'bcm_exercise_criterion_id');
    }

    /** @return HasMany<Finding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'bcm_exercise_result_id');
    }
}
