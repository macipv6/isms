<?php

namespace App\Models;

use App\Enums\RiskCycleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property RiskCycleStatus $status */
class RiskAnalysisCycle extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'risk_analysis_id', 'cycle_number', 'title', 'cause', 'event', 'impact', 'confidentiality', 'integrity', 'availability', 'status', 'is_current', 'needs_review', 'lock_version', 'created_by', 'updated_by', 'completed_by', 'completed_at'];

    protected function casts(): array
    {
        return ['status' => RiskCycleStatus::class, 'confidentiality' => 'boolean', 'integrity' => 'boolean', 'availability' => 'boolean', 'is_current' => 'boolean', 'needs_review' => 'boolean', 'completed_at' => 'immutable_datetime', 'lock_version' => 'integer', 'cycle_number' => 'integer'];
    }

    /** @return BelongsTo<RiskAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(RiskAnalysis::class, 'risk_analysis_id');
    }

    /** @return HasMany<RiskAssessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class, 'risk_analysis_cycle_id');
    }

    /** @return HasMany<RiskAnalysisTriggerLink, $this> */
    public function triggerLinks(): HasMany
    {
        return $this->hasMany(RiskAnalysisTriggerLink::class, 'risk_analysis_cycle_id');
    }

    /** @return HasOne<RiskTreatment, $this> */
    public function treatment(): HasOne
    {
        return $this->hasOne(RiskTreatment::class, 'risk_analysis_cycle_id');
    }

    /** @return HasMany<RiskAnalysisFindingLink, $this> */
    public function findingLinks(): HasMany
    {
        return $this->hasMany(RiskAnalysisFindingLink::class, 'risk_analysis_cycle_id');
    }

    /** @return HasMany<RiskAnalysisMeasureLink, $this> */
    public function measureLinks(): HasMany
    {
        return $this->hasMany(RiskAnalysisMeasureLink::class, 'risk_analysis_cycle_id');
    }

    /** @return HasMany<RiskAnalysisEvidenceLink, $this> */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(RiskAnalysisEvidenceLink::class, 'risk_analysis_cycle_id');
    }

    /** @return HasMany<RiskDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(RiskDecision::class, 'risk_analysis_cycle_id');
    }
}
