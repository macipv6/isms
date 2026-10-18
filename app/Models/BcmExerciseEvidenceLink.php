<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BcmExerciseEvidenceLink extends Model
{
    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['project_id', 'bcm_exercise_id', 'evidence_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BcmExercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(BcmExercise::class, 'bcm_exercise_id');
    }

    /** @return BelongsTo<EvidenceFile, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(EvidenceFile::class, 'evidence_id');
    }
}
