<?php

namespace App\Models;

use App\Enums\BcmRevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BcmRevisionStatus $status
 * @property int $version
 * @property int $lock_version
 * @property int|null $mtpd_minutes
 * @property int|null $rto_minutes
 * @property int|null $rpo_minutes
 * @property bool $rpo_not_applicable
 * @property array<string, mixed> $source_snapshot
 * @property CarbonImmutable|null $approved_at
 */
class BiaRevision extends Model
{
    use HasUuids;

    protected $attributes = ['rpo_not_applicable' => false];

    protected $fillable = ['project_id', 'business_impact_analysis_id', 'bia_configuration_version_id', 'version', 'lock_version', 'status', 'owner_name', 'mtpd_minutes', 'rto_minutes', 'rpo_minutes', 'rpo_not_applicable', 'rpo_reason', 'target_reason', 'source_snapshot', 'created_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['status' => BcmRevisionStatus::class, 'version' => 'integer', 'lock_version' => 'integer', 'mtpd_minutes' => 'integer', 'rto_minutes' => 'integer', 'rpo_minutes' => 'integer', 'rpo_not_applicable' => 'boolean', 'source_snapshot' => 'array', 'approved_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BusinessImpactAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(BusinessImpactAnalysis::class, 'business_impact_analysis_id');
    }

    /** @return BelongsTo<BiaConfigurationVersion, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(BiaConfigurationVersion::class, 'bia_configuration_version_id');
    }

    /** @return HasMany<BiaImpact, $this> */
    public function impacts(): HasMany
    {
        return $this->hasMany(BiaImpact::class);
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }
}
