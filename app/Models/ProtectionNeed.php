<?php

namespace App\Models;

use App\Enums\ProtectionLevel;
use App\Enums\ProtectionNeedStatus;
use App\Enums\ProtectionObjective;
use Database\Factories\ProtectionNeedFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ProtectionObjective $objective
 * @property ProtectionLevel|null $direct_level
 * @property ProtectionLevel|null $inherited_level
 * @property ProtectionLevel|null $effective_level
 * @property ProtectionNeedStatus $status
 * @property string|null $direct_rationale
 * @property int $lock_version
 */
class ProtectionNeed extends Model
{
    /** @use HasFactory<ProtectionNeedFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['project_id', 'business_process_id', 'asset_id', 'objective', 'direct_level', 'direct_rationale', 'inherited_level', 'effective_level', 'status', 'needs_review', 'rated_by', 'rated_at', 'lock_version'];

    protected function casts(): array
    {
        return ['objective' => ProtectionObjective::class, 'direct_level' => ProtectionLevel::class, 'inherited_level' => ProtectionLevel::class, 'effective_level' => ProtectionLevel::class, 'status' => ProtectionNeedStatus::class, 'needs_review' => 'boolean', 'rated_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /** @return HasMany<ProtectionNeedSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ProtectionNeedSource::class);
    }

    /** @return HasMany<ProtectionNeedDowngradeRequest, $this> */
    public function downgradeRequests(): HasMany
    {
        return $this->hasMany(ProtectionNeedDowngradeRequest::class);
    }
}
