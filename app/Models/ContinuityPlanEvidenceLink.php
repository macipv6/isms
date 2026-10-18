<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContinuityPlanEvidenceLink extends Model
{
    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['project_id', 'continuity_plan_revision_id', 'evidence_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ContinuityPlanRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(ContinuityPlanRevision::class, 'continuity_plan_revision_id');
    }

    /** @return BelongsTo<EvidenceFile, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(EvidenceFile::class, 'evidence_id');
    }
}
