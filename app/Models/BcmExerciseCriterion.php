<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $position
 * @property int|null $expected_minutes
 */
class BcmExerciseCriterion extends Model
{
    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['project_id', 'bcm_exercise_id', 'position', 'expected_result', 'expected_minutes'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'expected_minutes' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BcmExercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(BcmExercise::class, 'bcm_exercise_id');
    }

    /** @return HasOne<BcmExerciseResult, $this> */
    public function result(): HasOne
    {
        return $this->hasOne(BcmExerciseResult::class);
    }
}
