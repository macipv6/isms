<?php

namespace App\Services\Projects;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\ProjectThreat;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ProtectionNeeds\ProtectionNeedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectWorkflow
{
    public function __construct(private readonly ProtectionNeedAccess $access, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function update(IsmsProject $project, array $data, User $actor): IsmsProject
    {
        return DB::transaction(function () use ($project, $data, $actor): IsmsProject {
            $actor = $this->access->writer($actor);
            if ($actor->role !== UserRole::Admin) {
                throw ValidationException::withMessages(['actor' => 'Nur ein aktiver interner Administrator darf das Projekt ändern.']);
            }
            $project = $this->access->project($project);
            $before = $project->status;
            $project->fill($data);
            $changed = array_keys($project->getDirty());
            if ($changed === []) {
                return $project;
            }
            if ($project->status === ProjectStatus::Completed && $before !== ProjectStatus::Completed) {
                $this->assertCompletable($project);
            }
            $project->save();
            $this->audit->record('project.updated', $actor, ['project_id' => $project->id, 'changed_fields' => $changed], $project->organization_id);
            if (in_array('status', $changed, true)) {
                $this->audit->record('project.status_changed', $actor, ['project_id' => $project->id, 'old_status' => $before->value, 'new_status' => $project->status->value, 'changed_fields' => ['status']], $project->organization_id);
            }

            return $project;
        });
    }

    private function assertCompletable(IsmsProject $project): void
    {
        $currentCatalog = DB::table('project_risk_contexts')->where('project_id', $project->id)->value('catalog_version_id');
        $currentThreatIds = ProjectThreat::query()->where('project_id', $project->id)->currentForCatalog($currentCatalog)->select('id');
        $activeAnalyses = DB::table('risk_analyses as analyses')
            ->leftJoin('business_processes as processes', function ($join): void {
                $join->on('processes.id', '=', 'analyses.business_process_id')->on('processes.project_id', '=', 'analyses.project_id');
            })
            ->leftJoin('assets', function ($join): void {
                $join->on('assets.id', '=', 'analyses.asset_id')->on('assets.project_id', '=', 'analyses.project_id');
            })
            ->where('analyses.project_id', $project->id)->whereIn('analyses.project_threat_id', $currentThreatIds)
            ->where(fn ($query) => $query->where('processes.is_active', true)->orWhere('assets.is_active', true))
            ->pluck('analyses.id');
        foreach ($activeAnalyses as $analysisId) {
            $current = DB::table('risk_analysis_cycles')->where('project_id', $project->id)->where('risk_analysis_id', $analysisId)->where('is_current', true)->get(['status', 'needs_review']);
            if ($current->count() !== 1 || $current->first()->status !== 'completed' || $current->first()->needs_review) {
                throw ValidationException::withMessages(['status' => 'Das Projekt enthält offene oder veraltete Risikoanalysen.']);
            }
        }
        $requiredTriggers = DB::table('risk_analysis_triggers')->where('project_id', $project->id)
            ->whereIn('type', ['elevated_protection_need', 'insufficient_baseline_coverage', 'nonstandard_scenario'])
            ->where('status', '<>', 'superseded')->pluck('id');
        foreach ($requiredTriggers as $id) {
            $covered = DB::table('risk_analysis_trigger_links as links')
                ->join('risk_analysis_cycles as cycles', function ($join): void {
                    $join->on('cycles.id', '=', 'links.risk_analysis_cycle_id')->on('cycles.project_id', '=', 'links.project_id');
                })
                ->where('links.project_id', $project->id)->where('links.risk_analysis_trigger_id', $id)
                ->where('cycles.is_current', true)->where('cycles.needs_review', false)->where('cycles.status', 'completed')->exists();
            if (! $covered) {
                throw ValidationException::withMessages(['status' => 'Ein verpflichtender Auslöser hat keine aktuelle abgeschlossene Risikoanalyse.']);
            }
        }
    }
}
