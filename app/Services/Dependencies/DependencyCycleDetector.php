<?php

namespace App\Services\Dependencies;

use Illuminate\Validation\ValidationException;

class DependencyCycleDetector
{
    /**
     * @param  list<array{source: string, target: string}>  $edges
     * @param  array{source: string, target: string}  $candidate
     */
    public function edgeParticipatesInCycle(array $edges, array $candidate): bool
    {
        $adjacency = [];
        foreach ($edges as $edge) {
            if ($edge === $candidate) {
                continue;
            }
            $adjacency[$edge['source']][] = $edge['target'];
        }

        return $this->canReach($candidate['target'], $candidate['source'], $adjacency, []);
    }

    /**
     * @param  list<array{source: string, target: string}>  $existingEdges
     * @param  list<array{source: string, target: string}>  $candidateEdges
     */
    public function assertAcyclic(array $existingEdges, array $candidateEdges): void
    {
        $adjacency = [];
        foreach ([...$existingEdges, ...$candidateEdges] as $edge) {
            $adjacency[$edge['source']][] = $edge['target'];
        }

        $visiting = [];
        $visited = [];
        foreach (array_keys($adjacency) as $node) {
            if ($this->hasCycle($node, $adjacency, $visiting, $visited)) {
                throw ValidationException::withMessages([
                    'dependencies' => ['Die Abhängigkeiten enthalten einen Zyklus.'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     * @param  array<string, bool>  $visiting
     * @param  array<string, bool>  $visited
     */
    private function hasCycle(string $node, array $adjacency, array &$visiting, array &$visited): bool
    {
        if (isset($visiting[$node])) {
            return true;
        }
        if (isset($visited[$node])) {
            return false;
        }

        $visiting[$node] = true;
        foreach ($adjacency[$node] ?? [] as $target) {
            if ($this->hasCycle($target, $adjacency, $visiting, $visited)) {
                return true;
            }
        }
        unset($visiting[$node]);
        $visited[$node] = true;

        return false;
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     * @param  array<string, bool>  $visited
     */
    private function canReach(string $node, string $target, array $adjacency, array $visited): bool
    {
        if ($node === $target) {
            return true;
        }
        if (isset($visited[$node])) {
            return false;
        }

        $visited[$node] = true;
        foreach ($adjacency[$node] ?? [] as $next) {
            if ($this->canReach($next, $target, $adjacency, $visited)) {
                return true;
            }
        }

        return false;
    }
}
