<?php

namespace App\Services\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Models\FindingAssetLink;
use App\Models\FindingProcessLink;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ProtectionNeeds\ProtectionNeedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FindingRiskTargetService
{
    public function __construct(private readonly ProtectionNeedAccess $access, private readonly RiskTriggerEvaluator $evaluator, private readonly AuditLogger $audit, private readonly RiskChangeInvalidator $invalidator) {}

    public function link(Finding $finding, DependencyNode $target, User $actor): void
    {
        DB::transaction(function () use ($finding, $target, $actor): void {
            $actor = $this->access->writer($actor);
            $finding = Finding::query()->lockForUpdate()->findOrFail($finding->id);
            $project = $this->access->project(IsmsProject::query()->findOrFail($finding->project_id));
            if (! in_array($finding->status, [FindingStatus::Proposed, FindingStatus::Accepted], true)) {
                throw ValidationException::withMessages(['finding' => 'Nur offene oder akzeptierte Funde können mit einem Risikoziel verknüpft werden.']);
            }
            if ($target->projectId !== $project->id) {
                throw ValidationException::withMessages(['target' => 'Das Ziel gehört nicht zu diesem Projekt.']);
            }
            $this->access->target($project, $target);
            if ($target->type === DependencyNodeType::Process) {
                $link = FindingProcessLink::query()->firstOrCreate(['project_id' => $project->id, 'finding_id' => $finding->id, 'business_process_id' => $target->id]);
            } else {
                $link = FindingAssetLink::query()->firstOrCreate(['project_id' => $project->id, 'finding_id' => $finding->id, 'asset_id' => $target->id]);
            }
            if ($link->wasRecentlyCreated) {
                $this->audit->record('finding.target_linked', $actor, ['project_id' => $project->id, 'finding_id' => $finding->id, 'target_id' => $target->id, 'target_type' => $target->type->value], $project->organization_id);
                $this->invalidator->invalidateForTarget($project, $target, 'finding_link', $actor);
            }
            $this->evaluator->refreshTarget($project, $target, true, $actor);
        });
    }
}
