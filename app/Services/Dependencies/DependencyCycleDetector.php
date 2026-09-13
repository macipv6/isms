<?php

namespace App\Services\Dependencies;

use Illuminate\Validation\ValidationException;

class DependencyCycleDetector
{
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
}
