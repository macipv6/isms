<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $key
 * @property int $sort_order
 */
class RiskFrequencyLevel extends Model
{
    use HasUuids;

    protected $fillable = ['version_id', 'key', 'label', 'description', 'sort_order'];

    /** @return BelongsTo<RiskMethodologyVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(RiskMethodologyVersion::class, 'version_id');
    }
}
