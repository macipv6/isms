<?php

namespace App\Services\Dependencies;

use App\Data\Dependencies\DependencyNode;
use App\Data\Dependencies\TraversalHit;
use App\Enums\DependencyImportance;
use App\Enums\DependencyNodeType;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use Illuminate\Database\Eloquent\Builder;

class DependencyGraph
{
    /** @return list<TraversalHit> */
    public function dependencies(IsmsProject $project, DependencyNode $node, bool $transitive = false, bool $includeInactive = false): array
    {
        return $this->traverse($project, $node, false, $transitive, $includeInactive);
    }

    /** @return list<TraversalHit> */
    public function dependents(IsmsProject $project, DependencyNode $node, bool $transitive = false, bool $includeInactive = false): array
    {
        return $this->traverse($project, $node, true, $transitive, $includeInactive);
    }

    /** @return list<TraversalHit> */
    public function affectedProcesses(IsmsProject $project, DependencyNode $node, bool $transitive = true, bool $includeInactive = false): array
    {
        return array_values(array_filter(
            $this->dependents($project, $node, $transitive, $includeInactive),
            fn (TraversalHit $hit): bool => $hit->node->type === DependencyNodeType::Process,
        ));
    }

    /**
     * @return list<TraversalHit>
     */
    private function traverse(IsmsProject $project, DependencyNode $root, bool $reverse, bool $transitive, bool $includeInactive): array
    {
        abort_unless($root->projectId === $project->id, 404);
        $nodeLimit = $project->businessProcesses()->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true))->count()
            + $project->assets()->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true))->count();
        if ($nodeLimit === 0) {
            return [];
        }

        $visited = [$root->identity() => true];
        $frontier = [$root];
        $hits = [];
        $depth = 0;
        while ($frontier !== [] && count($visited) <= $nodeLimit) {
            $depth++;
            $edges = $this->frontierEdges($project, $frontier, $reverse, $includeInactive);
            $candidates = [];
            foreach ($edges as $edge) {
                $candidate = $this->nodeFromEdge($edge, $reverse ? 'source' : 'target');
                if ($candidate === null || isset($visited[$candidate->identity()])) {
                    continue;
                }
                if (! isset($candidates[$candidate->identity()]) || $edge->importance === DependencyImportance::Critical) {
                    $candidates[$candidate->identity()] = new TraversalHit($candidate, $depth, $edge->importance);
                }
            }
            $level = array_values($candidates);
            usort($level, fn (TraversalHit $left, TraversalHit $right): int => [$left->node->type->value, $left->node->key, $left->node->id] <=> [$right->node->type->value, $right->node->key, $right->node->id]);
            $nextFrontier = [];
            foreach ($level as $hit) {
                if (count($visited) >= $nodeLimit) {
                    break;
                }
                $visited[$hit->node->identity()] = true;
                $hits[] = $hit;
                $nextFrontier[] = $hit->node;
            }
            if (! $transitive) {
                break;
            }
            $frontier = $nextFrontier;
        }

        return $hits;
    }

    /**
     * @param  list<DependencyNode>  $frontier
     * @return list<DependencyEdge>
     */
    private function frontierEdges(IsmsProject $project, array $frontier, bool $reverse, bool $includeInactive): array
    {
        $processIds = [];
        $assetIds = [];
        foreach ($frontier as $node) {
            if ($node->type === DependencyNodeType::Process) {
                $processIds[] = $node->id;
            } else {
                $assetIds[] = $node->id;
            }
        }
        $side = $reverse ? 'target' : 'source';
        $query = DependencyEdge::query()
            ->with(['sourceProcess', 'sourceAsset', 'targetProcess', 'targetAsset'])
            ->where('project_id', $project->id)
            ->where(function (Builder $query) use ($side, $processIds, $assetIds): void {
                if ($processIds !== []) {
                    $query->whereIn("{$side}_process_id", $processIds);
                }
                if ($assetIds !== []) {
                    $method = $processIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}("{$side}_asset_id", $assetIds);
                }
            });
        if (! $includeInactive) {
            $query->where('is_active', true)
                ->where(function (Builder $query): void {
                    $query->whereHas('sourceProcess', fn (Builder $query) => $query->where('is_active', true))
                        ->orWhereHas('sourceAsset', fn (Builder $query) => $query->where('is_active', true));
                })
                ->where(function (Builder $query): void {
                    $query->whereHas('targetProcess', fn (Builder $query) => $query->where('is_active', true))
                        ->orWhereHas('targetAsset', fn (Builder $query) => $query->where('is_active', true));
                });
        }

        return $query->get()->all();
    }

    private function nodeFromEdge(DependencyEdge $edge, string $side): ?DependencyNode
    {
        $process = $side === 'source' ? $edge->sourceProcess : $edge->targetProcess;
        if ($process !== null) {
            return DependencyNode::process($process);
        }
        $asset = $side === 'source' ? $edge->sourceAsset : $edge->targetAsset;

        return $asset === null ? null : DependencyNode::asset($asset);
    }
}
