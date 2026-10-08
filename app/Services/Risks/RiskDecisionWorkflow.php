<?php

namespace App\Services\Risks;

use App\Enums\EvidenceReviewStatus;
use App\Enums\RiskAssessmentPhase;
use App\Enums\RiskCycleStatus;
use App\Enums\RiskDecisionChannel;
use App\Enums\RiskDecisionStatus;
use App\Models\EvidenceFile;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskDecision;
use App\Models\RiskDecisionEvidenceLink;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RiskDecisionWorkflow
{
    public function __construct(private readonly RiskCycleAccess $access, private readonly AuditLogger $audit) {}

    public function decideInternal(RiskAnalysisCycle $cycle, RiskDecisionStatus $status, string $rationale, string $decisionDate, User $decisionMaker): RiskDecision
    {
        return $this->decide($cycle, $status, RiskDecisionChannel::Internal, null, null, $decisionDate, $rationale, null, $decisionMaker);
    }

    public function recordExternal(RiskAnalysisCycle $cycle, RiskDecisionStatus $status, string $name, string $function, string $decisionDate, string $rationale, ?EvidenceFile $evidence, User $recorder): RiskDecision
    {
        return $this->decide($cycle, $status, RiskDecisionChannel::External, $name, $function, $decisionDate, $rationale, $evidence, $recorder);
    }

    private function decide(RiskAnalysisCycle $cycle, RiskDecisionStatus $status, RiskDecisionChannel $channel, ?string $name, ?string $function, string $date, string $rationale, ?EvidenceFile $evidence, User $actor): RiskDecision
    {
        $values = Validator::make(['rationale' => $rationale, 'decision_date' => $date, 'name' => $name, 'function' => $function], [
            'rationale' => ['required', 'string', 'max:10000'], 'decision_date' => ['required', 'date_format:Y-m-d'],
            'name' => [$channel === RiskDecisionChannel::External ? 'required' : 'nullable', 'string', 'max:255'],
            'function' => [$channel === RiskDecisionChannel::External ? 'required' : 'nullable', 'string', 'max:255'],
        ], ['required' => 'Dieses Feld ist erforderlich.', 'date_format' => 'Ein gültiges Datum ist erforderlich.', 'max' => 'Dieses Feld ist zu lang.'])->validate();
        foreach ($channel === RiskDecisionChannel::External ? ['rationale', 'name', 'function'] : ['rationale'] as $key) {
            if (trim((string) $values[$key]) === '') {
                throw ValidationException::withMessages([$key => 'Dieses Feld darf nicht leer sein.']);
            }
        }

        return DB::transaction(function () use ($cycle, $status, $channel, $values, $evidence, $actor): RiskDecision {
            [$project, $locked, $actor] = $this->access->lock($cycle, $actor, [RiskCycleStatus::AwaitingDecision]);
            $residual = $locked->currentResidualAssessment()->firstOrFail();
            if ($channel === RiskDecisionChannel::Internal && $residual->assessed_by === $actor->id) {
                throw ValidationException::withMessages(['decision_maker' => 'Die bewertende Person darf ihre eigene Risikoentscheidung nicht treffen.']);
            }
            if ($locked->decisions()->whereNull('invalidated_at')->exists()) {
                throw ValidationException::withMessages(['decision' => 'Für diesen Zyklus liegt bereits eine aktuelle Entscheidung vor.']);
            }
            if ($evidence) {
                $evidence = EvidenceFile::query()->whereKey($evidence->id)->where('project_id', $project->id)->lockForUpdate()->first();
                if (! $evidence || $evidence->status !== EvidenceReviewStatus::Verified) {
                    throw ValidationException::withMessages(['evidence' => 'Ein geprüfter Nachweis aus diesem Projekt ist erforderlich.']);
                }
            }
            $decision = $locked->decisions()->create([
                'project_id' => $project->id, 'residual_assessment_id' => $residual->id, 'assessment_phase' => RiskAssessmentPhase::Residual->value,
                'status' => $status, 'channel' => $channel,
                'rationale' => trim((string) $values['rationale']), 'decision_date' => $values['decision_date'],
                'recorded_by' => $actor->id,
                'internal_decision_maker_id' => $channel === RiskDecisionChannel::Internal ? $actor->id : null,
                'external_decision_maker_name' => $channel === RiskDecisionChannel::External ? trim((string) $values['name']) : null,
                'external_decision_maker_function' => $channel === RiskDecisionChannel::External ? trim((string) $values['function']) : null,
            ]);
            if ($evidence) {
                RiskDecisionEvidenceLink::query()->create(['project_id' => $project->id, 'risk_analysis_cycle_id' => $locked->id, 'risk_decision_id' => $decision->id, 'evidence_id' => $evidence->id]);
            }
            if ($status === RiskDecisionStatus::Rejected) {
                $decision->update(['invalidated_at' => now(), 'invalidated_by' => $actor->id, 'invalidation_reason' => 'rejected']);
                $locked->update(['status' => RiskCycleStatus::TreatmentInProgress, 'lock_version' => $locked->lock_version + 1, 'updated_by' => $actor->id]);
            } else {
                $locked->update(['lock_version' => $locked->lock_version + 1, 'updated_by' => $actor->id]);
            }
            $context = $channel === RiskDecisionChannel::External
                ? ['decision_channel' => $channel->value, 'decision_id' => $decision->id]
                : [
                    'project_id' => $project->id, 'target_id' => $locked->id, 'decision_id' => $decision->id,
                    'decision_channel' => $channel->value, 'new_status' => $status->value,
                    'decision_maker_id' => $actor->id, 'recorder_id' => $actor->id,
                ];
            $this->audit->record('risk_analysis.decision_recorded', $actor, $context, $project->organization_id);

            return $decision;
        });
    }
}
