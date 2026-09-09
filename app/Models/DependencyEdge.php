<?php

namespace App\Models;

use App\Enums\DependencyImportance;
use Carbon\CarbonImmutable;
use Database\Factories\DependencyEdgeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DependencyImportance $importance
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class DependencyEdge extends Model
{
    /** @use HasFactory<DependencyEdgeFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id', 'source_process_id', 'source_asset_id', 'target_process_id', 'target_asset_id',
        'importance', 'reason', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['importance' => DependencyImportance::class, 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /** @return BelongsTo<BusinessProcess, $this> */
    public function sourceProcess(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'source_process_id');
    }

    /** @return BelongsTo<Asset, $this> */
    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'source_asset_id');
    }

    /** @return BelongsTo<BusinessProcess, $this> */
    public function targetProcess(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'target_process_id');
    }

    /** @return BelongsTo<Asset, $this> */
    public function targetAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'target_asset_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
