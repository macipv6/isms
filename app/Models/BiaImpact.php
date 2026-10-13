<?php

namespace App\Models;

use App\Enums\BiaImpactArea;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property BiaImpactArea $area
 * @property int $minutes
 * @property int|null $level
 */
class BiaImpact extends Model
{
    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['project_id', 'bia_revision_id', 'area', 'minutes', 'level', 'reason'];

    protected function casts(): array
    {
        return ['area' => BiaImpactArea::class, 'minutes' => 'integer', 'level' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BiaRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(BiaRevision::class, 'bia_revision_id');
    }
}
