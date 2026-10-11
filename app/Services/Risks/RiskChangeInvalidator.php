<?php

namespace App\Services\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Enums\RiskCycleStatus;
use App\Models\IsmsProject;
use App\Models\RiskAnalysis;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskDecision;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class RiskChangeInvalidator
{
    public function __construct(private readonly AuditLogger $audit, private readonly RiskTriggerCoverage $coverage) {}

    public function invalidateForTarget(IsmsProject $project, DependencyNode $target, string $reason, ?User $actor = null): void
    {
        DB::transaction(function () use ($project, $target, $reason, $actor): void {
            $project = IsmsProject::query()->lockForUpdate()->findOrFail($project->id);
            $column = $target->type === DependencyNodeType::Process ? 'business_process_id' : 'asset_id';
            foreach (RiskAnalysis::query()->where('project_id', $project->id)->where($column, $target->id)->get() as $analysis) {
                $this->invalidateAnalysis($project, $analysis, $reason, $actor);
            }
        });
    }

    public function invalidateForProject(IsmsProject $project, string $reason, ?User $actor = null): void
    {
        DB::transaction(function () use ($project, $reason, $actor): void {
            $project = IsmsProject::query()->lockForUpdate()->findOrFail($project->id);
            foreach (RiskAnalysis::query()->where('project_id', $project->id)->get() as $analysis) {
                $this->invalidateAnalysis($project, $analysis, $reason, $actor);
            }
        });
    }

    public function invalidateForThreat(IsmsProject $project, string $threatId, string $reason, ?User $actor = null): void
    {
        DB::transaction(function () use ($project, $threatId, $reason, $actor): void {
            $project = IsmsProject::query()->lockForUpdate()->findOrFail($project->id);
            foreach (RiskAnalysis::query()->where('project_id', $project->id)->where('project_threat_id', $threatId)->get() as $analysis) {
                $this->invalidateAnalysis($project, $analysis, $reason, $actor);
            }
        });
    }

    public function invalidateForCycle(RiskAnalysisCycle $cycle, string $reason, ?User $actor = null): void
    {
        DB::transaction(function () use ($cycle, $reason, $actor): void {
            $project = IsmsProject::query()->lockForUpdate()->findOrFail($cycle->project_id);
            $locked = RiskAnalysisCycle::query()->whereKey($cycle->id)->where('project_id', $project->id)->lockForUpdate()->first();
            if ($locked && in_array($locked->status, [RiskCycleStatus::ResidualAssessed, RiskCycleStatus::AwaitingDecision, RiskCycleStatus::Completed], true)) {
                $this->invalidateCycle($project, $locked, $reason, $actor);
            }
        });
    }

    public function invalidateForLinkedResource(IsmsProject $project, string $kind, string $id, ?User $actor = null): void
    {
        if (! in_array($kind, ['finding', 'measure', 'evidence'], true)) {
            return;
        }
        DB::transaction(function () use ($project, $kind, $id, $actor): void {
            $project = IsmsProject::query()->lockForUpdate()->findOrFail($project->id);
            $table = 'risk_analysis_'.$kind.'_links';
            $ids = DB::table($table)->where('project_id', $project->id)->where($kind.'_id', $id)->pluck('risk_analysis_cycle_id');
            if ($kind === 'evidence') {
                $ids = $ids->merge(DB::table('risk_decision_evidence_links')->where('project_id', $project->id)->where('evidence_id', $id)->pluck('risk_analysis_cycle_id'))->unique();
                $ids = $ids->merge(DB::table('risk_treatments')->where('project_id', $project->id)->where('cessation_evidence_id', $id)->pluck('risk_analysis_cycle_id'))->unique();
            }
            foreach (RiskAnalysisCycle::query()->where('project_id', $project->id)->whereIn('id', $ids)->where('is_current', true)->where('needs_review', false)->lockForUpdate()->get() as $cycle) {
                if (in_array($cycle->status, [RiskCycleStatus::ResidualAssessed, RiskCycleStatus::AwaitingDecision, RiskCycleStatus::Completed], true)) {
                    $this->invalidateCycle($project, $cycle, $kind.'_change', $actor);
                }
            }
        });
    }

    private function invalidateAnalysis(IsmsProject $project, RiskAnalysis $analysis, string $reason, ?User $actor): void
    {
        $cycles = RiskAnalysisCycle::query()->where('risk_analysis_id', $analysis->id)->where('is_current', true)->where('needs_review', false)->lockForUpdate()->get();
        foreach ($cycles as $cycle) {
            $this->invalidateCycle($project, $cycle, $reason, $actor);
        }
    }

    private function invalidateCycle(IsmsProject $project, RiskAnalysisCycle $cycle, string $reason, ?User $actor): void
    {
        if ($cycle->needs_review || ! $cycle->is_current) {
            return;
        }
        $completed = $cycle->status === RiskCycleStatus::Completed;
        RiskDecision::query()->where('project_id', $project->id)->where('risk_analysis_cycle_id', $cycle->id)->whereNull('invalidated_at')->update([
            'invalidated_at' => now(), 'invalidated_by' => $actor?->id, 'invalidation_reason' => 'related_change',
        ]);
        $cycle->update($completed
            ? ['needs_review' => true]
            : ['needs_review' => true, 'is_current' => false, 'status' => RiskCycleStatus::Superseded, 'lock_version' => $cycle->lock_version + 1, 'updated_by' => $actor === null ? $cycle->updated_by : $actor->id]);
        foreach ($cycle->triggerLinks()->pluck('risk_analysis_trigger_id') as $triggerId) {
            $this->coverage->reopenIfUncovered($project, $triggerId);
        }
        $this->audit->record('risk_analysis.invalidated', $actor, [
            'project_id' => $project->id,
            'target_id' => $cycle->id,
            'reason' => in_array($reason, ['protection_need', 'provenance', 'topology', 'lifecycle', 'methodology', 'catalog', 'threat_replacement'], true) ? $reason : 'related_change',
            'new_status' => $completed ? 'needs_review' : RiskCycleStatus::Superseded->value,
        ], $project->organization_id);
    }
}
