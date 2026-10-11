<?php

namespace App\Services\Bcm;

use App\Data\Bcm\BcmWarning;
use App\Enums\DependencyNodeType;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\BiaRevision;

class BcmConsistencyChecker
{
    public function __construct(private readonly BcmDependencyTraversal $traversal, private readonly BiaConfigurationWorkflow $configuration) {}

    /**
     * Complete current source provenance; excludes root lock/status so approval does not invalidate it.
     *
     * @return array<string, mixed>
     */
    public function sourceSnapshot(BiaRevision $revision): array
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
                'critical' => $path->critical, 'path_edge_ids' => $path->pathEdgeIds, 'objective' => $objective,
                'stale' => $path->targetType === DependencyNodeType::Process && $objective !== null && $objective['configuration_id'] !== $current?->id];
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

    /** @return list<BcmWarning> */
    public function warnings(BiaRevision $revision): array
    {
        $sources = $this->sourceSnapshot($revision);
        $root = $sources['root_targets'];
        $warnings = [];
        foreach ($sources['dependencies'] as $dependency) {
            $objective = $dependency['objective'];
            $codes = [];
            if ($objective === null) {
                $codes[] = 'dependency_target_unknown';
            } else {
                if ($dependency['stale']) {
                    $codes[] = 'dependency_target_stale';
                }
                if ($root['rto_minutes'] !== null && $objective['rto_minutes'] !== null && $objective['rto_minutes'] > $root['rto_minutes']) {
                    $codes[] = 'dependency_rto_exceeds_process';
                }
                if (! $root['rpo_not_applicable'] && ! $objective['rpo_not_applicable'] && $root['rpo_minutes'] !== null && $objective['rpo_minutes'] !== null && $objective['rpo_minutes'] > $root['rpo_minutes']) {
                    $codes[] = 'dependency_rpo_exceeds_process';
                }
            }
            foreach ($codes as $code) {
                $warnings[] = $this->warning($code, $dependency['target_type'], $dependency['target_id'], $objective['id'] ?? null,
                    $dependency['path_edge_ids'], $dependency['critical'], $sources, $dependency);
            }
        }
        $firstCritical = null;
        $previous = [];
        $descending = [];
        foreach ($sources['impacts'] as $impact) {
            if ($impact['level'] === 4) {
                $firstCritical = $firstCritical === null ? $impact['minutes'] : min($firstCritical, $impact['minutes']);
            }
            if ($impact['level'] !== null) {
                if (isset($previous[$impact['area']]) && $impact['level'] < $previous[$impact['area']]) {
                    $descending[] = $impact;
                }
                $previous[$impact['area']] = $impact['level'];
            }
        }
        if ($firstCritical !== null && $root['mtpd_minutes'] !== null && $root['mtpd_minutes'] > $firstCritical) {
            $warnings[] = $this->warning('mtpd_after_critical_impact', 'process', $sources['root_process_id'], $sources['root_revision_id'], [], true, $sources, ['first_critical_minutes' => $firstCritical]);
        }
        if ($descending !== []) {
            $warnings[] = $this->warning('descending_impact', 'process', $sources['root_process_id'], $sources['root_revision_id'], [], true, $sources, ['descending' => $descending]);
        }

        return $warnings;
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

    /**
     * @param  list<string>  $path
     * @param  array<string, mixed>  $sources
     * @param  array<string, mixed>  $detail
     */
    private function warning(string $code, string $type, string $id, ?string $revision, array $path, bool $blocking, array $sources, array $detail): BcmWarning
    {
        $relevant = ['project_id' => $sources['project_id'], 'root_process_id' => $sources['root_process_id'],
            'root_revision_id' => $sources['root_revision_id'], 'root_version' => $sources['root_version'],
            'root_targets' => $sources['root_targets'], 'configuration' => $sources['configuration']];
        if ($path === []) {
            $relevant['impacts'] = $sources['impacts'];
        } else {
            $relevant['edges'] = array_values(array_filter($sources['edges'], fn ($edge) => in_array($edge['id'], $path, true)));
        }
        $fingerprint = hash('sha256', json_encode(['code' => $code, 'sources' => $relevant, 'detail' => $detail], JSON_THROW_ON_ERROR));

        return new BcmWarning($code, DependencyNodeType::from($type), $id, $revision, $path, $blocking, $fingerprint);
    }
}
