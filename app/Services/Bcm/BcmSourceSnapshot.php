<?php

namespace App\Services\Bcm;

use App\Enums\DependencyNodeType;
use App\Models\Asset;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\BiaConfigurationVersion;
use App\Models\BiaImpact;
use App\Models\BiaRevision;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;

class BcmSourceSnapshot
{
    public function __construct(private readonly BcmDependencyTraversal $traversal, private readonly BiaConfigurationWorkflow $configuration) {}

    /**
     * Complete current source provenance; excludes root lock/status so approval does not invalidate it.
     *
     * @return array<string, mixed>
     */
    public function bia(BiaRevision $revision): array
    {
        $revision = BiaRevision::query()->with(['analysis.process', 'configuration', 'impacts', 'project'])->whereKey($revision->id)->where('project_id', $revision->project_id)->firstOrFail();
        $project = $revision->project;
        $current = $this->configuration->current($project);
        $graph = $this->traversal->provenance($project, $revision->analysis->process);
        $processIds = [];
        $assetIds = [];
        foreach ($graph['paths'] as $path) {
            if ($path->targetType === DependencyNodeType::Process) {
                $processIds[] = $path->targetId;
            } else {
                $assetIds[] = $path->targetId;
            }
        }
        $objectives = [];
        $processObjectives = BiaRevision::query()->with(['analysis', 'configuration'])->where('project_id', $project->id)->where('status', 'approved')
            ->whereHas('analysis', fn ($query) => $query->where('project_id', $project->id)->whereIn('business_process_id', $processIds))
            ->orderByDesc('version')->orderBy('id')->get();
        foreach ($processObjectives as $objective) {
            $key = 'process:'.$objective->analysis->business_process_id;
            $objectives[$key] ??= $this->objective($objective);
        }
        $assetObjectives = AssetRecoveryObjectiveRevision::query()->where('project_id', $project->id)->where('status', 'approved')
            ->whereIn('asset_id', $assetIds)->orderByDesc('version')->orderBy('id')->get();
        foreach ($assetObjectives as $objective) {
            $objectives['asset:'.$objective->asset_id] ??= $this->objective($objective);
        }
        $dependencies = [];
        foreach ($graph['paths'] as $path) {
            $objective = $objectives[$path->targetType->value.':'.$path->targetId] ?? null;
            $dependencies[] = ['target_type' => $path->targetType->value, 'target_id' => $path->targetId,
                'critical' => $path->critical, 'path_edge_ids' => $path->pathEdgeIds, 'objective' => $objective];
        }
        $impacts = [];
        foreach ($revision->impacts->sortBy(fn ($impact) => [$impact->area->value, $impact->minutes]) as $impact) {
            $impacts[] = ['area' => $impact->area->value, 'minutes' => $impact->minutes, 'level' => $impact->level, 'reason' => $impact->reason];
        }

        return ['project_id' => $project->id, 'root_process_id' => $revision->analysis->business_process_id,
            'root_revision_id' => $revision->id, 'root_version' => $revision->version,
            'configuration' => ['pinned_id' => $revision->configuration->id, 'pinned_version' => $revision->configuration->version,
                'pinned_minutes' => $revision->configuration->minutes, 'current_id' => $current?->id,
                'current_version' => $current?->version, 'current_minutes' => $current?->minutes],
            'root_targets' => $this->objective($revision), 'impacts' => $impacts,
            'dependencies' => $dependencies, 'edges' => $graph['edges']];
    }

    /** Caller must already hold the project row lock inside a transaction. */
    public function lockProjectSources(IsmsProject $project): void
    {
        foreach ([BusinessProcess::class, Asset::class, DependencyEdge::class, BiaConfigurationVersion::class, BiaRevision::class, AssetRecoveryObjectiveRevision::class, BiaImpact::class] as $model) {
            $model::query()->where('project_id', $project->id)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    /** @return array<string, mixed> */
    private function objective(BiaRevision|AssetRecoveryObjectiveRevision $revision): array
    {
        return ['id' => $revision->id, 'version' => $revision->version, 'rto_minutes' => $revision->rto_minutes,
            'rpo_minutes' => $revision->rpo_minutes, 'rpo_not_applicable' => $revision->rpo_not_applicable,
            'mtpd_minutes' => $revision instanceof BiaRevision ? $revision->mtpd_minutes : null,
            'configuration_id' => $revision instanceof BiaRevision ? $revision->bia_configuration_version_id : null,
            'configuration_version' => $revision instanceof BiaRevision ? $revision->configuration->version : null,
            'configuration_minutes' => $revision instanceof BiaRevision ? $revision->configuration->minutes : null];
    }
}
