<?php

namespace App\Services\Risks;

use App\Enums\EvidenceReviewStatus;
use App\Enums\RiskCycleStatus;
use App\Enums\RiskTreatmentAction;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\Measure;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskTreatment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RiskTreatmentWorkflow
{
    public function __construct(private readonly RiskCycleAccess $access, private readonly AuditLogger $audit, private readonly RiskChangeInvalidator $invalidator) {}

    /** @param array<string, mixed> $details */
    public function select(RiskAnalysisCycle $cycle, RiskTreatmentAction $action, array $details, User $actor): RiskTreatment
    {
        $validated = Validator::make($details, [
            'rationale' => ['required', 'string', 'max:10000'],
            'counterparty_name' => [$action === RiskTreatmentAction::Transfer ? 'required' : 'nullable', 'string', 'max:255'],
            'counterparty_email' => ['nullable', 'email', 'max:255'],
            'cessation_statement' => [$action === RiskTreatmentAction::Avoid ? 'required' : 'nullable', 'string', 'max:10000'],
            'cessation_evidence_id' => [$action === RiskTreatmentAction::Avoid ? 'required' : 'nullable', 'uuid'],
        ], ['required' => 'Dieses Feld ist erforderlich.', 'max' => 'Dieses Feld ist zu lang.', 'email' => 'Eine gültige E-Mail-Adresse ist erforderlich.'])->validate();
        foreach (['rationale', $action === RiskTreatmentAction::Transfer ? 'counterparty_name' : 'rationale', $action === RiskTreatmentAction::Avoid ? 'cessation_statement' : 'rationale'] as $key) {
            if (trim((string) $validated[$key]) === '') {
                throw ValidationException::withMessages([$key => 'Dieses Feld darf nicht leer sein.']);
            }
        }

        return DB::transaction(function () use ($cycle, $action, $validated, $actor): RiskTreatment {
            [$project, $locked, $actor] = $this->access->lock($cycle, $actor, [RiskCycleStatus::InherentAssessed, RiskCycleStatus::TreatmentInProgress, RiskCycleStatus::ResidualAssessed, RiskCycleStatus::AwaitingDecision]);
            if ($action === RiskTreatmentAction::Avoid) {
                $proof = EvidenceFile::query()->whereKey($validated['cessation_evidence_id'])->where('project_id', $project->id)->lockForUpdate()->first();
                if (! $proof || $proof->status !== EvidenceReviewStatus::Verified || ! $proof->reviewed_by || ! $proof->reviewed_at) {
                    throw ValidationException::withMessages(['cessation_evidence_id' => 'Ein geprüfter Stilllegungsnachweis aus diesem Projekt ist erforderlich.']);
                }
            }
            $treatment = $locked->treatment()->first();
            $attributes = [
                'project_id' => $project->id, 'action' => $action, 'rationale' => trim((string) $validated['rationale']),
                'counterparty_name' => $action === RiskTreatmentAction::Transfer ? trim((string) $validated['counterparty_name']) : null,
                'counterparty_email' => $action === RiskTreatmentAction::Transfer ? ($validated['counterparty_email'] ?? null) : null,
                'cessation_statement' => $action === RiskTreatmentAction::Avoid ? trim((string) $validated['cessation_statement']) : null,
                'cessation_evidence_id' => $action === RiskTreatmentAction::Avoid ? $validated['cessation_evidence_id'] : null,
                'selected_by' => $actor->id,
            ];
            $event = $treatment ? 'risk_analysis.treatment_changed' : 'risk_analysis.treatment_selected';
            if ($treatment) {
                $treatment->fill($attributes);
                if (! $treatment->isDirty()) {
                    return $treatment;
                }
                $treatment->save();
            } else {
                $treatment = $locked->treatment()->create($attributes);
            }
            if ($locked->status === RiskCycleStatus::InherentAssessed) {
                $locked->update(['status' => RiskCycleStatus::TreatmentInProgress, 'updated_by' => $actor->id, 'lock_version' => $locked->lock_version + 1]);
            } else {
                $locked->update(['updated_by' => $actor->id, 'lock_version' => $locked->lock_version + 1]);
                $this->invalidator->invalidateForCycle($locked, 'treatment_change', $actor);
            }
            $this->audit->record($event, $actor, ['project_id' => $project->id, 'target_id' => $locked->id, 'source_id' => $treatment->id, 'new_status' => $locked->status->value, 'action' => $action->value], $project->organization_id);

            return $treatment;
        });
    }

    public function linkFinding(RiskAnalysisCycle $cycle, Finding $finding, User $actor): void
    {
        $this->link($cycle, $finding, 'finding', $actor);
    }

    public function linkMeasure(RiskAnalysisCycle $cycle, Measure $measure, User $actor): void
    {
        $this->link($cycle, $measure, 'measure', $actor);
    }

    public function linkEvidence(RiskAnalysisCycle $cycle, EvidenceFile $evidence, User $actor): void
    {
        $this->link($cycle, $evidence, 'evidence', $actor);
    }

    public function unlinkFinding(RiskAnalysisCycle $cycle, Finding $finding, User $actor): void
    {
        $this->unlink($cycle, $finding, 'finding', $actor);
    }

    public function unlinkMeasure(RiskAnalysisCycle $cycle, Measure $measure, User $actor): void
    {
        $this->unlink($cycle, $measure, 'measure', $actor);
    }

    public function unlinkEvidence(RiskAnalysisCycle $cycle, EvidenceFile $evidence, User $actor): void
    {
        $this->unlink($cycle, $evidence, 'evidence', $actor);
    }

    private function link(RiskAnalysisCycle $cycle, Finding|Measure|EvidenceFile $resource, string $kind, User $actor): void
    {
        DB::transaction(function () use ($cycle, $resource, $kind, $actor): void {
            [$project, $locked, $actor] = $this->access->lock($cycle, $actor, [RiskCycleStatus::InherentAssessed, RiskCycleStatus::TreatmentInProgress, RiskCycleStatus::ResidualAssessed, RiskCycleStatus::AwaitingDecision]);
            $resourceClass = $resource::class;
            if (! $resourceClass::query()->whereKey($resource->id)->where('project_id', $project->id)->exists()) {
                throw ValidationException::withMessages([$kind => 'Die Verknüpfung gehört nicht zu diesem Projekt.']);
            }
            $relation = $kind.'Links';
            $link = $locked->{$relation}()->firstOrCreate(['project_id' => $project->id, $kind.'_id' => $resource->id]);
            if ($link->wasRecentlyCreated) {
                $locked->update(['lock_version' => $locked->lock_version + 1, 'updated_by' => $actor->id]);
                $this->invalidator->invalidateForCycle($locked, $kind.'_link', $actor);
                $this->audit->record('risk_analysis.'.$kind.'_linked', $actor, ['project_id' => $project->id, 'target_id' => $locked->id, 'source_id' => $resource->id], $project->organization_id);
            }
        });
    }

    private function unlink(RiskAnalysisCycle $cycle, Finding|Measure|EvidenceFile $resource, string $kind, User $actor): void
    {
        DB::transaction(function () use ($cycle, $resource, $kind, $actor): void {
            [$project, $locked, $actor] = $this->access->lock($cycle, $actor, [RiskCycleStatus::InherentAssessed, RiskCycleStatus::TreatmentInProgress, RiskCycleStatus::ResidualAssessed, RiskCycleStatus::AwaitingDecision]);
            $relation = $kind.'Links';
            $deleted = $locked->{$relation}()->where('project_id', $project->id)->where($kind.'_id', $resource->id)->delete();
            if ($deleted > 0) {
                $locked->update(['lock_version' => $locked->lock_version + 1, 'updated_by' => $actor->id]);
                $this->invalidator->invalidateForCycle($locked, $kind.'_unlink', $actor);
                $this->audit->record('risk_analysis.'.$kind.'_unlinked', $actor, ['project_id' => $project->id, 'target_id' => $locked->id, 'source_id' => $resource->id], $project->organization_id);
            }
        });
    }
}
