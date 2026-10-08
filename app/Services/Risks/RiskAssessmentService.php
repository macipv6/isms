<?php

namespace App\Services\Risks;

use App\Enums\RiskAssessmentPhase;
use App\Enums\RiskCycleStatus;
use App\Models\IsmsProject;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskAssessment;
use App\Models\RiskFrequencyLevel;
use App\Models\RiskImpactLevel;
use App\Models\RiskMethodologyVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ProtectionNeeds\ProtectionNeedAccess;
use App\Services\RiskMethodology\RiskMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiskAssessmentService
{
    public function __construct(private readonly ProtectionNeedAccess $access, private readonly RiskMatrix $matrix, private readonly AuditLogger $audit, private readonly RiskCycleAccess $cycles, private readonly RiskTreatmentPrerequisites $prerequisites) {}

    public function assessInherent(RiskAnalysisCycle $cycle, string $frequencyKey, string $impactKey, string $rationale, User $actor, int $expectedVersion): RiskAssessment
    {
        $rationale = trim($rationale);
        if ($rationale === '' || mb_strlen($rationale) > 10000) {
            throw ValidationException::withMessages(['rationale' => 'Eine Begründung mit höchstens 10000 Zeichen ist erforderlich.']);
        }

        return DB::transaction(function () use ($cycle, $frequencyKey, $impactKey, $rationale, $actor, $expectedVersion): RiskAssessment {
            $actor = $this->access->writer($actor);
            $current = RiskAnalysisCycle::query()->findOrFail($cycle->id);
            $project = $this->access->project(IsmsProject::query()->findOrFail($current->project_id));
            $locked = RiskAnalysisCycle::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($cycle->id);
            if ($locked->status !== RiskCycleStatus::Draft || ! $locked->is_current || $locked->needs_review || $locked->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['cycle' => 'Der Risikozyklus wurde geändert. Bitte laden Sie ihn neu.']);
            }
            $versionId = DB::table('project_risk_contexts')->where('project_id', $project->id)->value('methodology_version_id');
            $version = RiskMethodologyVersion::query()->findOrFail($versionId);
            $frequency = RiskFrequencyLevel::query()->where('version_id', $version->id)->where('key', $frequencyKey)->first();
            $impact = RiskImpactLevel::query()->where('version_id', $version->id)->where('key', $impactKey)->first();
            if (! $frequency || ! $impact) {
                throw ValidationException::withMessages(['matrix' => 'Die gewählten Bewertungsstufen gehören nicht zur aktuellen Methodik.']);
            }
            $risk = $this->matrix->category($version, $frequencyKey, $impactKey);
            $assessment = $locked->assessments()->create([
                'project_id' => $project->id, 'phase' => RiskAssessmentPhase::Inherent, 'methodology_version_id' => $version->id,
                'frequency_level_id' => $frequency->id, 'impact_level_id' => $impact->id, 'risk_level_id' => $risk->id,
                'rationale' => $rationale, 'assessed_by' => $actor->id, 'assessed_at' => now(),
            ]);
            $locked->update(['status' => RiskCycleStatus::InherentAssessed, 'updated_by' => $actor->id, 'lock_version' => $locked->lock_version + 1]);
            $this->audit->record('risk_analysis.inherent_assessed', $actor, ['project_id' => $project->id, 'target_id' => $locked->id, 'new_level' => $risk->key, 'new_status' => RiskCycleStatus::InherentAssessed->value], $project->organization_id);

            return $assessment;
        });
    }

    public function assessResidual(RiskAnalysisCycle $cycle, string $frequencyKey, string $impactKey, string $rationale, User $actor, int $expectedVersion): RiskAssessment
    {
        $rationale = trim($rationale);
        if ($rationale === '' || mb_strlen($rationale) > 10000) {
            throw ValidationException::withMessages(['rationale' => 'Eine Begründung mit höchstens 10000 Zeichen ist erforderlich.']);
        }

        return DB::transaction(function () use ($cycle, $frequencyKey, $impactKey, $rationale, $actor, $expectedVersion): RiskAssessment {
            [$project, $locked, $actor] = $this->cycles->lock($cycle, $actor, [RiskCycleStatus::TreatmentInProgress], $expectedVersion);
            $treatment = $locked->treatment()->lockForUpdate()->first();
            if (! $treatment) {
                throw ValidationException::withMessages(['treatment' => 'Eine Risikobehandlung ist erforderlich.']);
            }
            $versionId = DB::table('project_risk_contexts')->where('project_id', $project->id)->value('methodology_version_id');
            $version = RiskMethodologyVersion::query()->findOrFail($versionId);
            $frequency = RiskFrequencyLevel::query()->where('version_id', $version->id)->where('key', $frequencyKey)->first();
            $impact = RiskImpactLevel::query()->where('version_id', $version->id)->where('key', $impactKey)->first();
            if (! $frequency || ! $impact) {
                throw ValidationException::withMessages(['matrix' => 'Die gewählten Bewertungsstufen gehören nicht zur aktuellen Methodik.']);
            }
            $inherent = $locked->assessments()->where('phase', RiskAssessmentPhase::Inherent->value)->firstOrFail();
            if ($inherent->methodology_version_id !== $version->id) {
                throw ValidationException::withMessages(['matrix' => 'Die Methodik wurde geändert. Eine neue Analyse ist erforderlich.']);
            }
            $this->prerequisites->assert($locked, $treatment, $frequency, $impact);
            $risk = $this->matrix->category($version, $frequencyKey, $impactKey);
            $existing = $locked->assessments()->where('phase', RiskAssessmentPhase::Residual->value)->first();
            if ($existing) {
                $existing->delete();
            }
            $assessment = $locked->assessments()->create([
                'project_id' => $project->id, 'phase' => RiskAssessmentPhase::Residual, 'methodology_version_id' => $version->id,
                'frequency_level_id' => $frequency->id, 'impact_level_id' => $impact->id, 'risk_level_id' => $risk->id,
                'rationale' => $rationale, 'assessed_by' => $actor->id, 'assessed_at' => now(),
            ]);
            $next = in_array($risk->key, ['high', 'very_high'], true) ? RiskCycleStatus::AwaitingDecision : RiskCycleStatus::ResidualAssessed;
            $locked->update(['status' => $next, 'lock_version' => $locked->lock_version + 1, 'updated_by' => $actor->id]);
            $this->audit->record('risk_analysis.residual_assessed', $actor, ['project_id' => $project->id, 'target_id' => $locked->id, 'new_level' => $risk->key, 'new_status' => $next->value], $project->organization_id);

            return $assessment;
        });
    }
}
