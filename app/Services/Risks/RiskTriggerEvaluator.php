<?php

namespace App\Services\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Enums\RiskTriggerStatus;
use App\Enums\RiskTriggerType;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\RiskAnalysisTrigger;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ProtectionNeeds\ProtectionNeedAccess;
use App\Services\Threats\ThreatOverviewService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiskTriggerEvaluator
{
    public function __construct(private readonly ProtectionNeedAccess $access, private readonly ThreatOverviewService $overview, private readonly AuditLogger $audit) {}

    /** @return Collection<int, RiskAnalysisTrigger> */
    public function refreshTarget(IsmsProject $project, DependencyNode $target, bool $recordAudit = true, ?User $actor = null): Collection
    {
        return DB::transaction(function () use ($project, $target, $recordAudit, $actor): Collection {
            $project = $this->access->project($project);
            $this->access->target($project, $target, false);
            $column = $this->targetColumn($target);
            $targetModel = $target->type === DependencyNodeType::Process ? BusinessProcess::class : Asset::class;
            if (! $targetModel::query()->whereKey($target->id)->where('project_id', $project->id)->where('is_active', true)->exists()) {
                $open = RiskAnalysisTrigger::query()->where('project_id', $project->id)->where($column, $target->id)->whereIn('status', [RiskTriggerStatus::Open->value, RiskTriggerStatus::Covered->value])->lockForUpdate()->get();
                foreach ($open as $trigger) {
                    $trigger->update(['status' => RiskTriggerStatus::Superseded]);
                    if ($recordAudit) {
                        $this->auditStatus($project, $trigger, 'risk_trigger.superseded', $actor);
                    }
                }

                return new Collection;
            }
            $desired = [];
            if (DB::table('protection_needs')->where('project_id', $project->id)->where($column, $target->id)->whereIn('effective_level', ['high', 'very_high'])->exists()) {
                $desired[RiskTriggerType::ElevatedProtectionNeed->value.':effective'] = [RiskTriggerType::ElevatedProtectionNeed, 'effective', null];
            }
            $linkTable = $target->type === DependencyNodeType::Process ? 'finding_process_links' : 'finding_asset_links';
            $findingIds = DB::table($linkTable.' as links')->join('findings', function ($join): void {
                $join->on('findings.id', '=', 'links.finding_id')->on('findings.project_id', '=', 'links.project_id');
            })->where('links.project_id', $project->id)->where('links.'.$column, $target->id)->where('findings.status', 'accepted')->pluck('findings.id');
            foreach ($findingIds as $findingId) {
                $desired[RiskTriggerType::InsufficientBaselineCoverage->value.':'.$findingId] = [RiskTriggerType::InsufficientBaselineCoverage, $findingId, $findingId];
            }
            foreach ($desired as [$type, $identity, $findingId]) {
                $this->open($project, $target, $type, $identity, $findingId, null, $actor, $recordAudit);
            }
            $derived = RiskAnalysisTrigger::query()->where('project_id', $project->id)->where($column, $target->id)->whereIn('type', [RiskTriggerType::ElevatedProtectionNeed->value, RiskTriggerType::InsufficientBaselineCoverage->value])->whereIn('status', [RiskTriggerStatus::Open->value, RiskTriggerStatus::Covered->value])->lockForUpdate()->get();
            foreach ($derived as $trigger) {
                if (! isset($desired[$trigger->type->value.':'.$trigger->source_identity])) {
                    $trigger->update(['status' => RiskTriggerStatus::Superseded]);
                    if ($recordAudit) {
                        $this->auditStatus($project, $trigger, 'risk_trigger.superseded', $actor);
                    }
                }
            }
            $active = RiskAnalysisTrigger::query()->where('project_id', $project->id)->where($column, $target->id)->whereIn('status', [RiskTriggerStatus::Open->value, RiskTriggerStatus::Covered->value])->get();
            if ($active->contains(fn (RiskAnalysisTrigger $t): bool => $t->type->mandatory())) {
                $this->overview->ensureTarget($project, $target);
            }

            return $active;
        });
    }

    public function register(IsmsProject $project, DependencyNode $target, RiskTriggerType $type, string $rationale, User $actor): RiskAnalysisTrigger
    {
        if (! in_array($type, [RiskTriggerType::NonstandardScenario, RiskTriggerType::Manual], true)) {
            throw ValidationException::withMessages(['type' => 'Diese Auslöserart kann nicht manuell angelegt werden.']);
        }
        $rationale = trim($rationale);
        if ($rationale === '' || mb_strlen($rationale) > 10000) {
            throw ValidationException::withMessages(['rationale' => 'Eine Begründung mit höchstens 10000 Zeichen ist erforderlich.']);
        }

        return DB::transaction(function () use ($project, $target, $type, $rationale, $actor): RiskAnalysisTrigger {
            $actor = $this->access->writer($actor);
            $project = $this->access->project($project);
            $this->access->target($project, $target);
            $identity = hash('sha256', $actor->id."\0".$rationale);
            $trigger = $this->open($project, $target, $type, $identity, null, $rationale, $actor);
            if ($type->mandatory()) {
                $this->overview->ensureTarget($project, $target);
            }

            return $trigger;
        });
    }

    public function dismiss(RiskAnalysisTrigger $trigger, User $actor): void
    {
        DB::transaction(function () use ($trigger, $actor): void {
            $actor = $this->access->writer($actor);
            $locked = RiskAnalysisTrigger::query()->lockForUpdate()->findOrFail($trigger->id);
            $project = $this->access->project(IsmsProject::query()->findOrFail($locked->project_id));
            if ($locked->type !== RiskTriggerType::Manual || $locked->created_by !== $actor->id || $locked->status !== RiskTriggerStatus::Open) {
                throw ValidationException::withMessages(['trigger' => 'Nur eigene offene manuelle Auslöser können zurückgenommen werden.']);
            }
            // A completed cycle cannot exist before Task 6 introduces analyses.
            $locked->update(['status' => RiskTriggerStatus::Superseded]);
            $this->auditStatus($project, $locked, 'risk_trigger.superseded', $actor);
        });
    }

    private function open(IsmsProject $project, DependencyNode $target, RiskTriggerType $type, string $identity, ?string $findingId, ?string $rationale, ?User $actor, bool $recordAudit = true): RiskAnalysisTrigger
    {
        $where = ['project_id' => $project->id, $this->targetColumn($target) => $target->id, 'type' => $type->value, 'source_identity' => $identity];
        $trigger = RiskAnalysisTrigger::query()->where($where)->orderByDesc('created_at')->lockForUpdate()->first();
        if ($trigger && ! ($trigger->status === RiskTriggerStatus::Superseded && $type === RiskTriggerType::Manual)) {
            if ($trigger->status === RiskTriggerStatus::Superseded) {
                $trigger->update(['status' => RiskTriggerStatus::Open]);
                if ($recordAudit) {
                    $this->auditStatus($project, $trigger, 'risk_trigger.opened', $actor);
                }
            }

            return $trigger;
        }
        $trigger = RiskAnalysisTrigger::query()->create([...$where, 'source_finding_id' => $findingId, 'rationale' => $rationale, 'created_by' => $actor?->id, 'status' => RiskTriggerStatus::Open]);
        if ($recordAudit) {
            $this->auditStatus($project, $trigger, 'risk_trigger.opened', $actor);
        }

        return $trigger;
    }

    private function targetColumn(DependencyNode $target): string
    {
        return $target->type === DependencyNodeType::Process ? 'business_process_id' : 'asset_id';
    }

    private function auditStatus(IsmsProject $project, RiskAnalysisTrigger $trigger, string $event, ?User $actor = null): void
    {
        $this->audit->record($event, $actor, [
            'project_id' => $project->id,
            'target_type' => $trigger->business_process_id !== null ? 'process' : 'asset',
            'target_id' => $trigger->business_process_id ?? $trigger->asset_id,
            'source_type' => $trigger->type->value,
            'source_id' => $trigger->id,
            'new_status' => $trigger->status->value,
        ], $project->organization_id);
    }
}
