<?php

namespace App\Services\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Enums\RiskCycleStatus;
use App\Models\IsmsProject;
use App\Models\RiskAnalysis;
use App\Models\RiskAnalysisCycle;
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

    private function invalidateAnalysis(IsmsProject $project, RiskAnalysis $analysis, string $reason, ?User $actor): void
    {
        $cycles = RiskAnalysisCycle::query()->where('risk_analysis_id', $analysis->id)->where('is_current', true)->where('needs_review', false)->lockForUpdate()->get();
        foreach ($cycles as $cycle) {
            $completed = $cycle->status === RiskCycleStatus::Completed;
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
}
