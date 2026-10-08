<?php

namespace App\Services\Risks;

use App\Enums\RiskAssessmentPhase;
use App\Enums\RiskCycleStatus;
use App\Enums\RiskDecisionStatus;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskAnalysisTrigger;
use App\Models\RiskFrequencyLevel;
use App\Models\RiskImpactLevel;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiskCycleCompleter
{
    public function __construct(private readonly RiskCycleAccess $access, private readonly AuditLogger $audit, private readonly RiskTreatmentPrerequisites $prerequisites) {}

    public function complete(RiskAnalysisCycle $cycle, User $actor, int $expectedVersion): RiskAnalysisCycle
    {
        return DB::transaction(function () use ($cycle, $actor, $expectedVersion): RiskAnalysisCycle {
            [$project, $locked, $actor] = $this->access->lock($cycle, $actor, [RiskCycleStatus::ResidualAssessed, RiskCycleStatus::AwaitingDecision], $expectedVersion);
            $assessment = $locked->assessments()->where('phase', RiskAssessmentPhase::Residual->value)->with('riskLevel')->first();
            $treatment = $locked->treatment()->first();
            if (! $assessment || ! $treatment) {
                throw ValidationException::withMessages(['cycle' => 'Eine gültige Restbewertung und Behandlung sind erforderlich.']);
            }
            $this->prerequisites->assert($locked, $treatment, RiskFrequencyLevel::query()->findOrFail($assessment->frequency_level_id), RiskImpactLevel::query()->findOrFail($assessment->impact_level_id));
            $high = in_array($assessment->riskLevel->key, ['high', 'very_high'], true);
            if ($high) {
                $decision = $locked->decisions()->whereNull('invalidated_at')->where('status', RiskDecisionStatus::Accepted->value)->first();
                if (! $decision || $locked->status !== RiskCycleStatus::AwaitingDecision) {
                    throw ValidationException::withMessages(['decision' => 'Ein aktueller Managemententscheid zur Risikoakzeptanz ist erforderlich.']);
                }
                if (DB::table('risk_decision_evidence_links as links')->join('evidence_files as evidence', function ($join): void {
                    $join->on('evidence.id', '=', 'links.evidence_id')->on('evidence.project_id', '=', 'links.project_id');
                })->where('links.risk_decision_id', $decision->id)->where('evidence.status', '<>', 'verified')->exists()) {
                    throw ValidationException::withMessages(['evidence' => 'Der Entscheidungsnachweis ist nicht mehr geprüft.']);
                }
            } elseif ($locked->status !== RiskCycleStatus::ResidualAssessed) {
                throw ValidationException::withMessages(['cycle' => 'Der Risikozyklus ist nicht abschließbar.']);
            }
            foreach ($locked->triggerLinks()->pluck('risk_analysis_trigger_id') as $id) {
                $trigger = RiskAnalysisTrigger::query()->whereKey($id)->where('project_id', $project->id)->lockForUpdate()->first();
                if ($trigger && $trigger->status->value === 'open') {
                    $trigger->update(['status' => 'covered']);
                }
            }
            $locked->update(['status' => RiskCycleStatus::Completed, 'completed_by' => $actor->id, 'completed_at' => now(), 'lock_version' => $locked->lock_version + 1, 'updated_by' => $actor->id]);
            $this->audit->record('risk_analysis.completed', $actor, ['project_id' => $project->id, 'target_id' => $locked->id, 'new_status' => RiskCycleStatus::Completed->value, 'new_level' => $assessment->riskLevel->key], $project->organization_id);

            return $locked;
        });
    }
}
