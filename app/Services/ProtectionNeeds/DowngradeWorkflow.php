<?php

namespace App\Services\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DowngradeRequestStatus;
use App\Enums\EvidenceReviewStatus;
use App\Enums\ProtectionLevel;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
use App\Models\ProtectionNeedDowngradeRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DowngradeWorkflow
{
    public function __construct(private readonly ProtectionNeedAccess $access, private readonly ProtectionNeedPropagator $propagator, private readonly AuditLogger $audit) {}

    public function request(ProtectionNeed $need, ProtectionLevel $requested, string $rationale, User $actor): ProtectionNeedDowngradeRequest
    {
        return DB::transaction(function () use ($need, $requested, $rationale, $actor): ProtectionNeedDowngradeRequest {
            $actor = $this->access->writer($actor);
            $need = ProtectionNeed::query()->whereKey($need->id)->lockForUpdate()->firstOrFail();
            $project = $this->access->project($need->project);
            $this->access->target($project, $this->node($need));
            if (! $need->inherited_level || $need->inherited_level->rank() <= $requested->rank() || $need->direct_level !== $requested) {
                $this->reject('requested_level', 'Die angefragte Herabstufung ist nicht mehr aktuell.');
            }
            if (trim($rationale) === '') {
                $this->reject('rationale', 'Eine Begründung ist erforderlich.');
            }
            if ($need->downgradeRequests()->where('status', 'pending')->exists()) {
                $this->reject('request', 'Es gibt bereits eine offene Herabstufung.');
            }
            $need->downgradeRequests()->where('status', 'approved')->update(['status' => 'superseded']);
            $request = $need->downgradeRequests()->create([
                'project_id' => $project->id, 'inherited_level' => $need->inherited_level,
                'requested_level' => $requested, 'rationale' => trim($rationale),
                'requested_by' => $actor->id, 'status' => DowngradeRequestStatus::Pending,
            ]);
            $this->audit->record('protection_need.downgrade_requested', $actor, ['project_id' => $project->id, 'protection_need_id' => $need->id, 'objective' => $need->objective->value, 'new_level' => $requested->value, 'new_status' => 'pending'], $project->organization_id);
            $this->propagator->recalculate($project, [$this->node($need)], $actor);

            return $request;
        });
    }

    public function decideInternal(ProtectionNeedDowngradeRequest $request, bool $approved, string $rationale, User $decider): ProtectionNeedDowngradeRequest
    {
        return DB::transaction(function () use ($request, $approved, $rationale, $decider): ProtectionNeedDowngradeRequest {
            $decider = $this->access->writer($decider);
            [$request, $need, $project] = $this->current($request);
            if ($request->requested_by === $decider->id) {
                $this->reject('decider', 'Eine zweite interne Person muss entscheiden.');
            }
            if (trim($rationale) === '') {
                $this->reject('rationale', 'Eine Entscheidungsbegründung ist erforderlich.');
            }
            $request->update(['status' => $approved ? DowngradeRequestStatus::Approved : DowngradeRequestStatus::Rejected, 'decision_channel' => 'internal', 'decided_by' => $decider->id, 'decision_rationale' => trim($rationale), 'decided_at' => now()]);
            $this->auditDecision($request, $need, $project, $decider);
            $this->propagator->recalculate($project, [$this->node($need)], $decider);

            return $request->refresh();
        });
    }

    public function recordExternal(ProtectionNeedDowngradeRequest $request, bool $approved, string $name, string $function, string $decisionDate, string $rationale, ?EvidenceFile $evidence, User $recorder): ProtectionNeedDowngradeRequest
    {
        return DB::transaction(function () use ($request, $approved, $name, $function, $decisionDate, $rationale, $evidence, $recorder): ProtectionNeedDowngradeRequest {
            $recorder = $this->access->writer($recorder);
            [$request, $need, $project] = $this->current($request);
            $data = Validator::make(compact('name', 'function', 'decisionDate', 'rationale'), [
                'name' => ['required', 'string', 'max:160'], 'function' => ['required', 'string', 'max:160'],
                'decisionDate' => ['required', 'date_format:Y-m-d'], 'rationale' => ['required', 'string'],
            ])->validate();
            foreach (['name', 'function', 'rationale'] as $key) {
                if (trim($data[$key]) === '') {
                    $this->reject($key, 'Die externe Entscheidung muss vollständig dokumentiert sein.');
                }
            }
            if ($evidence) {
                $currentEvidence = EvidenceFile::query()->whereKey($evidence->id)->where('project_id', $project->id)->first();
                if (! $currentEvidence || $currentEvidence->status !== EvidenceReviewStatus::Verified) {
                    $this->reject('evidence', 'Nur geprüfte Nachweise aus diesem Projekt sind zulässig.');
                }
            }
            $request->update([
                'status' => $approved ? DowngradeRequestStatus::Approved : DowngradeRequestStatus::Rejected,
                'decision_channel' => 'external', 'recorded_by' => $recorder->id,
                'external_name' => trim($name), 'external_function' => trim($function),
                'external_decision_date' => $decisionDate, 'decision_rationale' => trim($rationale),
                'external_evidence_id' => $evidence?->id, 'decided_at' => now(),
            ]);
            $this->auditDecision($request, $need, $project, $recorder);
            $this->propagator->recalculate($project, [$this->node($need)], $recorder);

            return $request->refresh();
        });
    }

    /** @return array{ProtectionNeedDowngradeRequest, ProtectionNeed, IsmsProject} */
    private function current(ProtectionNeedDowngradeRequest $request): array
    {
        $request = ProtectionNeedDowngradeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        $need = ProtectionNeed::query()->whereKey($request->protection_need_id)->where('project_id', $request->project_id)->lockForUpdate()->firstOrFail();
        $project = $this->access->project($need->project);
        $this->access->target($project, $this->node($need));
        if ($request->status !== DowngradeRequestStatus::Pending || $request->requested_level !== $need->direct_level || $request->inherited_level !== $need->inherited_level || $need->inherited_level->rank() <= $request->requested_level->rank()) {
            $this->reject('request', 'Die Herabstufung ist nicht mehr aktuell.');
        }

        return [$request, $need, $project];
    }

    private function node(ProtectionNeed $need): DependencyNode
    {
        if ($need->business_process_id) {
            $process = BusinessProcess::query()->whereKey($need->business_process_id)->where('project_id', $need->project_id)->firstOrFail();

            return DependencyNode::process($process);
        }
        $asset = Asset::query()->whereKey($need->asset_id)->where('project_id', $need->project_id)->firstOrFail();

        return DependencyNode::asset($asset);
    }

    private function auditDecision(ProtectionNeedDowngradeRequest $request, ProtectionNeed $need, IsmsProject $project, User $actor): void
    {
        $this->audit->record('protection_need.downgrade_decided', $actor, [
            'project_id' => $project->id, 'protection_need_id' => $need->id,
            'downgrade_request_id' => $request->id, 'objective' => $need->objective->value,
            'new_level' => $request->requested_level->value, 'new_status' => $request->status->value,
            'decision_channel' => $request->decision_channel,
        ], $project->organization_id);
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
