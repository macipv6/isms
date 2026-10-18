<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $role
 * @property string|null $name
 * @property string|null $deputy_name
 */
class ContinuityPlanRole extends Model
{
    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['project_id', 'continuity_plan_revision_id', 'position', 'role', 'name', 'email', 'phone', 'deputy_name'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ContinuityPlanRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(ContinuityPlanRevision::class, 'continuity_plan_revision_id');
    }
}
