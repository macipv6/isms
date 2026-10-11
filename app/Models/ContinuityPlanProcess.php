<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $business_process_id */
class ContinuityPlanProcess extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'continuity_plan_revision_id', 'business_process_id', 'bia_revision_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ContinuityPlanRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(ContinuityPlanRevision::class, 'continuity_plan_revision_id');
    }

    /** @return BelongsTo<BusinessProcess, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'business_process_id');
    }

    /** @return BelongsTo<BiaRevision, $this> */
    public function bia(): BelongsTo
    {
        return $this->belongsTo(BiaRevision::class, 'bia_revision_id');
    }
}
