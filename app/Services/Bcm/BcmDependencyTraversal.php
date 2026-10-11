<?php

namespace App\Services\Bcm;

use App\Data\Bcm\BcmDependencyPath;
use App\Enums\DependencyImportance;
use App\Enums\DependencyNodeType;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use Illuminate\Database\Eloquent\Builder;

class BcmDependencyTraversal
{
    /** @return list<BcmDependencyPath> */
    public function dependencies(IsmsProject $project, BusinessProcess $process): array
    {
        return $this->provenance($project, $process)['paths'];
    }

    /** @return array{paths: list<BcmDependencyPath>, edges: list<array<string, mixed>>} */
    public function provenance(IsmsProject $project, BusinessProcess $process): array
    {
        abort_unless($process->project_id === $project->id, 404);
        $process = BusinessProcess::query()->where('project_id', $project->id)->whereKey($process->id)->where('is_active', true)->firstOrFail();
        $root = 'process:'.$process->id;
        $visited = [$root.':0' => true, $root.':1' => true];
        $frontier = [new BcmDependencyPath(DependencyNodeType::Process, $process->id, true, [])];
        $paths = [];
        $sources = [];
        while ($frontier !== []) {
            $byNode = [];
            foreach ($frontier as $path) {
                $byNode[$path->targetType->value.':'.$path->targetId][] = $path;
            }
            $edges = [];
            foreach (array_chunk(array_keys($byNode), 100) as $chunk) {
                $processIds = [];
                $assetIds = [];
                foreach ($chunk as $node) {
                    [$type, $id] = explode(':', $node, 2);
                    if ($type === 'process') {
                        $processIds[] = $id;
                    } else {
                        $assetIds[] = $id;
                    }
                }
                $batch = DependencyEdge::query()->with(['targetProcess', 'targetAsset'])
                    ->where('project_id', $project->id)->where('is_active', true)
                    ->where(function (Builder $query) use ($processIds, $assetIds): void {
                        $query->whereIn('source_process_id', $processIds)->orWhereIn('source_asset_id', $assetIds);
                    })->get();
                foreach ($batch as $edge) {
                    $edges[$edge->id] = $edge;
                }
            }
            ksort($edges, SORT_STRING);
            $next = [];
            foreach ($edges as $edge) {
                $target = $edge->targetProcess ?? $edge->targetAsset;
                if (! $target || ! $target->is_active || $target->project_id !== $project->id) {
                    continue;
                }
                $type = $edge->target_process_id ? DependencyNodeType::Process : DependencyNodeType::Asset;
                $sourceNode = ($edge->source_process_id ? 'process:' : 'asset:').($edge->source_process_id ?? $edge->source_asset_id);
                $sources[$edge->id] = ['id' => $edge->id, 'source_type' => $edge->source_process_id ? 'process' : 'asset',
                    'source_id' => $edge->source_process_id ?? $edge->source_asset_id, 'target_type' => $type->value,
                    'target_id' => $target->id, 'importance' => $edge->importance->value];
                foreach ($byNode[$sourceNode] as $parent) {
                    $critical = $parent->critical && $edge->importance === DependencyImportance::Critical;
                    $key = $type->value.':'.$target->id.':'.(int) $critical;
                    if (isset($visited[$key])) {
                        continue;
                    }
                    $path = new BcmDependencyPath($type, $target->id, $critical, [...$parent->pathEdgeIds, $edge->id]);
                    if (! isset($next[$key]) || $path->pathEdgeIds < $next[$key]->pathEdgeIds) {
                        $next[$key] = $path;
                    }
                }
            }
            ksort($next, SORT_STRING);
            foreach ($next as $key => $path) {
                $visited[$key] = true;
                $paths[$key] = $path;
            }
            $frontier = array_values($next);
        }
        ksort($paths, SORT_STRING);
        ksort($sources, SORT_STRING);

        return ['paths' => array_values($paths), 'edges' => array_values($sources)];
    }
}
