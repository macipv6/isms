<?php

namespace Tests\Unit\Dependencies;

use App\Services\Dependencies\DependencyCycleDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DependencyCycleDetectorTest extends TestCase
{
    #[Test]
    public function it_identifies_only_cycle_edges_in_one_large_graph_analysis(): void
    {
        $edges = [];
        foreach (range(0, 999) as $index) {
            $edges[] = ['source' => 'N'.$index, 'target' => 'N'.($index + 1)];
        }
        $edges[] = ['source' => 'N900', 'target' => 'N895'];
        $edges[] = ['source' => 'N900', 'target' => 'TAIL'];

        $cycleEdges = app(DependencyCycleDetector::class)->cycleEdgeKeys($edges);

        $this->assertSame([
            'N895>N896' => true,
            'N896>N897' => true,
            'N897>N898' => true,
            'N898>N899' => true,
            'N899>N900' => true,
            'N900>N895' => true,
        ], $cycleEdges);
    }
}
