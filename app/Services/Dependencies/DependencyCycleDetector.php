<?php

namespace App\Services\Dependencies;

use App\Data\Dependencies\DependencyNode;
use App\Models\IsmsProject;
use Illuminate\Validation\ValidationException;

class DependencyCycleDetector
{
    public function __construct(private readonly DependencyGraph $graph) {}

    public function assertCanConnect(IsmsProject $project, DependencyNode $source, DependencyNode $target): void
    {
        foreach ($this->graph->activeDependenciesForCycleCheck($project, $target) as $hit) {
            if ($hit->node->identity() === $source->identity()) {
                throw ValidationException::withMessages([
                    'dependencies' => ['Die Abhängigkeiten enthalten einen Zyklus.'],
                ]);
            }
        }
    }

    /**
     * @param  list<array{source: string, target: string}>  $edges
     * @return array<string, true>
     */
    public function cycleEdgeKeys(array $edges): array
    {
        $adjacency = [];
        $nodes = [];
        foreach ($edges as $edge) {
            $adjacency[$edge['source']][] = $edge['target'];
            $nodes[$edge['source']] = true;
            $nodes[$edge['target']] = true;
        }

        $nextIndex = 0;
        $indices = [];
        $lowLinks = [];
        $stack = [];
        $onStack = [];
        $componentByNode = [];
        $componentSizes = [];
        foreach (array_keys($nodes) as $node) {
            if (! isset($indices[$node])) {
                $this->strongConnect($node, $adjacency, $nextIndex, $indices, $lowLinks, $stack, $onStack, $componentByNode, $componentSizes);
            }
        }

        $cycleEdges = [];
        foreach ($edges as $edge) {
            $component = $componentByNode[$edge['source']];
            if ($component === $componentByNode[$edge['target']] && ($componentSizes[$component] > 1 || $edge['source'] === $edge['target'])) {
                $cycleEdges[$edge['source'].'>'.$edge['target']] = true;
            }
        }

        return $cycleEdges;
    }

    /**
     * @param  list<array{source: string, target: string}>  $existingEdges
     * @param  list<array{source: string, target: string}>  $candidateEdges
     */
    public function assertAcyclic(array $existingEdges, array $candidateEdges): void
    {
        if ($this->cycleEdgeKeys([...$existingEdges, ...$candidateEdges]) !== []) {
            throw ValidationException::withMessages([
                'dependencies' => ['Die Abhängigkeiten enthalten einen Zyklus.'],
            ]);
        }
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     * @param  array<string, int>  $indices
     * @param  array<string, int>  $lowLinks
     * @param  list<string>  $stack
     * @param  array<string, true>  $onStack
     * @param  array<string, int>  $componentByNode
     * @param  array<int, int>  $componentSizes
     */
    private function strongConnect(
        string $node,
        array $adjacency,
        int &$nextIndex,
        array &$indices,
        array &$lowLinks,
        array &$stack,
        array &$onStack,
        array &$componentByNode,
        array &$componentSizes,
    ): void {
        $indices[$node] = $nextIndex;
        $lowLinks[$node] = $nextIndex;
        $nextIndex++;
        $stack[] = $node;
        $onStack[$node] = true;

        foreach ($adjacency[$node] ?? [] as $target) {
            if (! isset($indices[$target])) {
                $this->strongConnect($target, $adjacency, $nextIndex, $indices, $lowLinks, $stack, $onStack, $componentByNode, $componentSizes);
                $lowLinks[$node] = min($lowLinks[$node], $lowLinks[$target]);
            } elseif (isset($onStack[$target])) {
                $lowLinks[$node] = min($lowLinks[$node], $indices[$target]);
            }
        }

        if ($lowLinks[$node] !== $indices[$node]) {
            return;
        }

        $component = count($componentSizes);
        $componentSizes[$component] = 0;
        do {
            $member = array_pop($stack);
            if ($member === null) {
                break;
            }
            unset($onStack[$member]);
            $componentByNode[$member] = $component;
            $componentSizes[$component]++;
        } while ($member !== $node);
    }
}
