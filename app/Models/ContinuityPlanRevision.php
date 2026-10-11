<?php

namespace App\Models;

use App\Enums\BcmRevisionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BcmRevisionStatus $status
 * @property int $version
 * @property int $lock_version
 * @property array<string, mixed> $source_snapshot
 */
class ContinuityPlanRevision extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'continuity_plan_id', 'version', 'lock_version', 'status', 'title', 'scenario', 'activation_conditions', 'strategy', 'minimum_operation', 'normal_operation_return', 'source_snapshot', 'created_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['status' => BcmRevisionStatus::class, 'version' => 'integer', 'lock_version' => 'integer', 'source_snapshot' => 'array', 'approved_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ContinuityPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContinuityPlan::class, 'continuity_plan_id');
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /** @return HasMany<ContinuityPlanProcess, $this> */
    public function processes(): HasMany
    {
        return $this->hasMany(ContinuityPlanProcess::class);
    }

    /** @return HasMany<ContinuityPlanStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ContinuityPlanStep::class);
    }

    /** @return HasMany<ContinuityPlanRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(ContinuityPlanRole::class);
    }

    /** @return HasMany<ContinuityPlanEvidenceLink, $this> */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(ContinuityPlanEvidenceLink::class);
    }

    /** @return HasMany<ContinuityPlanRiskLink, $this> */
    public function riskLinks(): HasMany
    {
        return $this->hasMany(ContinuityPlanRiskLink::class);
    }
}
