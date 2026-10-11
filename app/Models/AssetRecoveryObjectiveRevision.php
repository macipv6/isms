<?php

namespace App\Models;

use App\Enums\BcmRevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property BcmRevisionStatus $status
 * @property int $version
 * @property int $lock_version
 * @property int|null $rto_minutes
 * @property int|null $rpo_minutes
 * @property bool $rpo_not_applicable
 * @property array<string, mixed> $source_snapshot
 * @property CarbonImmutable|null $approved_at
 */
class AssetRecoveryObjectiveRevision extends Model
{
    use HasUuids;

    protected $attributes = ['rpo_not_applicable' => false];

    protected $fillable = ['project_id', 'asset_id', 'version', 'lock_version', 'status', 'rto_minutes', 'rpo_minutes', 'rpo_not_applicable', 'rpo_reason', 'target_reason', 'source_snapshot', 'created_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['status' => BcmRevisionStatus::class, 'version' => 'integer', 'lock_version' => 'integer', 'rto_minutes' => 'integer', 'rpo_minutes' => 'integer', 'rpo_not_applicable' => 'boolean', 'source_snapshot' => 'array', 'approved_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }
}
