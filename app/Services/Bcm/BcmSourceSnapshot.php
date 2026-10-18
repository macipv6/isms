<?php

namespace App\Services\Bcm;

use App\Enums\DependencyNodeType;
use App\Models\Asset;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\BcmExercise;
use App\Models\BiaConfigurationVersion;
use App\Models\BiaImpact;
use App\Models\BiaRevision;
use App\Models\BusinessProcess;
use App\Models\ContinuityPlanRevision;
use App\Models\DependencyEdge;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

    /** Resolve current approved heads from server-owned project sources.
     * @return array<string, mixed>
     */
    public function plan(ContinuityPlanRevision $revision): array
    {
        $processes = [];
        $assets = [];
        foreach ($revision->processes()->orderBy('business_process_id')->get() as $link) {
            $bia = BiaRevision::query()->where('project_id', $revision->project_id)->where('status', 'approved')
                ->whereHas('analysis', fn ($query) => $query->where('project_id', $revision->project_id)->where('business_process_id', $link->business_process_id))
                ->orderByDesc('version')->first();
            $sources = $bia ? $this->bia($bia) : null;
            $processes[] = ['process_id' => $link->business_process_id, 'revision_id' => $bia?->id, 'version' => $bia?->version, 'sources' => $sources];
            foreach ($sources['dependencies'] ?? [] as $dependency) {
                if ($dependency['target_type'] === 'asset') {
                    $assets[$dependency['target_id']] = ['asset_id' => $dependency['target_id'], 'revision_id' => $dependency['objective']['id'] ?? null, 'version' => $dependency['objective']['version'] ?? null];
                }
            }
        }
        ksort($assets);

        return ['project_id' => $revision->project_id, 'processes' => $processes, 'assets' => array_values($assets),
            'evidence_ids' => $revision->evidenceLinks()->orderBy('evidence_id')->pluck('evidence_id')->all(),
            'risk_analysis_ids' => $revision->riskLinks()->orderBy('risk_analysis_id')->pluck('risk_analysis_id')->all()];
    }

    /** Current relevant raw sources for an explicit exercise acknowledgement; never replaces pinned history.
     * @return array<string, mixed>
     */
    public function exercise(BcmExercise $exercise): array
    {
        $plan = ContinuityPlanRevision::query()->whereKey($exercise->continuity_plan_revision_id)->where('project_id', $exercise->project_id)->firstOrFail();
        try {
            $current = $this->plan($plan);
        } catch (ModelNotFoundException|NotFoundHttpException) {
            $current = ['unavailable' => true, 'processes' => [], 'assets' => [], 'evidence_ids' => $plan->evidenceLinks()->orderBy('evidence_id')->pluck('evidence_id')->all()];
        }
        $queue = [];
        $processIds = [];
        $assetIds = [];
        foreach ($plan->processes()->orderBy('business_process_id')->get() as $link) {
            $processIds[$link->business_process_id] = true;
            $head = BiaRevision::query()->where('project_id', $exercise->project_id)->where('status', 'approved')
                ->whereHas('analysis', fn ($query) => $query->where('project_id', $exercise->project_id)->where('business_process_id', $link->business_process_id))
                ->orderByDesc('version')->first();
            if ($head) {
                $queue[] = $head->id;
            }
        }
        foreach ([$current, $plan->source_snapshot] as $snapshot) {
            foreach ($snapshot['processes'] ?? [] as $process) {
                $processIds[$process['process_id']] = true;
                if ($process['revision_id'] !== null) {
                    $queue[] = $process['revision_id'];
                }
            }
            foreach ($snapshot['assets'] ?? [] as $asset) {
                $assetIds[$asset['asset_id']] = true;
            }
        }
        $visited = [];
        $bias = [];
        while ($queue !== []) {
            $id = array_pop($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $bia = BiaRevision::query()->whereKey($id)->where('project_id', $exercise->project_id)->first();
            try {
                $bias[$id] = $bia ? $this->bia($bia) : null;
            } catch (ModelNotFoundException|NotFoundHttpException) {
                $bias[$id] = null;
            }
            if (! $bia) {
                continue;
            }
            $processIds[$bia->analysis->business_process_id] = true;
            // Include formerly selected targets as well, so deactivation cannot disappear from provenance.
            foreach ([$bias[$id] ?? [], $bia->source_snapshot['sources'] ?? []] as $sources) {
                foreach ($sources['dependencies'] ?? [] as $dependency) {
                    if ($dependency['target_type'] === 'asset') {
                        $assetIds[$dependency['target_id']] = true;
                    } else {
                        $processIds[$dependency['target_id']] = true;
                        if (($dependency['objective']['id'] ?? null) !== null) {
                            $queue[] = $dependency['objective']['id'];
                        }
                        $targetHead = BiaRevision::query()->where('project_id', $exercise->project_id)->where('status', 'approved')
                            ->whereHas('analysis', fn ($query) => $query->where('project_id', $exercise->project_id)->where('business_process_id', $dependency['target_id']))
                            ->orderByDesc('version')->first();
                        if ($targetHead) {
                            $queue[] = $targetHead->id;
                        }
                    }
                }
            }
        }
        ksort($bias);
        ksort($processIds);
        ksort($assetIds);
        $assetObjectives = [];
        foreach (array_keys($assetIds) as $id) {
            $objective = AssetRecoveryObjectiveRevision::query()->where('project_id', $exercise->project_id)->where('asset_id', $id)->where('status', 'approved')->orderByDesc('version')->first();
            $assetObjectives[$id] = $objective ? $this->objective($objective) : null;
        }
        $active = [];
        foreach (['process' => [BusinessProcess::class, array_keys($processIds)], 'asset' => [Asset::class, array_keys($assetIds)]] as $type => [$model, $ids]) {
            foreach ($ids as $id) {
                $target = $model::query()->whereKey($id)->where('project_id', $exercise->project_id)->first();
                $active[] = ['type' => $type, 'id' => $id, 'is_active' => $target?->is_active];
            }
        }
        $evidenceIds = array_values(array_unique([...$current['evidence_ids'], ...$exercise->evidenceLinks()->pluck('evidence_id')->all()]));
        sort($evidenceIds);
        $evidence = [];
        foreach ($evidenceIds as $id) {
            $proof = EvidenceFile::query()->whereKey($id)->where('project_id', $exercise->project_id)->first();
            $evidence[] = ['id' => $id, 'status' => $proof?->status->value, 'reviewed_by' => $proof?->reviewed_by, 'reviewed_at' => $proof?->getRawOriginal('reviewed_at')];
        }
        $head = ContinuityPlanRevision::query()->where('project_id', $exercise->project_id)->where('continuity_plan_id', $plan->continuity_plan_id)->where('status', 'approved')->orderByDesc('version')->first();
        $configuration = $this->configuration->current($plan->project);

        return ['plan_revision_id' => $plan->id, 'approved_head_id' => $head?->id, 'configuration' => ['id' => $configuration?->id, 'version' => $configuration?->version, 'minutes' => $configuration?->minutes], 'plan_sources' => $current, 'bia_sources' => $bias, 'asset_objectives' => $assetObjectives, 'active_targets' => $active, 'evidence' => $evidence];
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
