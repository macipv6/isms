<?php

namespace Tests\Feature\Dependencies;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyImportance;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dependencies\DependencyGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependencyTraversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependencies_are_breadth_first_stably_ordered_deduplicated_and_at_shortest_depth(): void
    {
        [$project, $actor] = $this->context();
        $root = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'ROOT']);
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC-Z']);
        $assetA = Asset::factory()->for($project, 'project')->create(['key' => 'ASSET-A']);
        $assetB = Asset::factory()->for($project, 'project')->create(['key' => 'ASSET-B']);
        $assetC = Asset::factory()->for($project, 'project')->create(['key' => 'ASSET-C']);
        $this->edge($project, $actor, $root, $process, DependencyImportance::Critical);
        $this->edge($project, $actor, $root, $assetB);
        $this->edge($project, $actor, $root, $assetA);
        $this->edge($project, $actor, $process, $assetC);
        $this->edge($project, $actor, $assetA, $assetC, DependencyImportance::Critical);

        $hits = app(DependencyGraph::class)->dependencies($project, DependencyNode::process($root), transitive: true);

        $this->assertSame([
            'asset:ASSET-A:1:supporting',
            'asset:ASSET-B:1:supporting',
            'process:PROC-Z:1:critical',
            'asset:ASSET-C:2:critical',
        ], array_map(fn ($hit): string => "{$hit->node->type->value}:{$hit->node->key}:{$hit->depth}:{$hit->importance->value}", $hits));
    }

    public function test_dependents_and_affected_processes_follow_reverse_edges(): void
    {
        [$project, $actor] = $this->context();
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'DB']);
        $application = Asset::factory()->for($project, 'project')->create(['key' => 'APP']);
        $direct = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'ORDER']);
        $indirect = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'SALES']);
        $this->edge($project, $actor, $application, $asset);
        $this->edge($project, $actor, $direct, $asset);
        $this->edge($project, $actor, $indirect, $application);
        $graph = app(DependencyGraph::class);

        $dependents = $graph->dependents($project, DependencyNode::asset($asset), transitive: true);
        $affected = $graph->affectedProcesses($project, DependencyNode::asset($asset));

        $this->assertSame(['asset:APP:1', 'process:ORDER:1', 'process:SALES:2'], $this->keys($dependents));
        $this->assertSame(['process:ORDER:1', 'process:SALES:2'], $this->keys($affected));
    }

    public function test_inactive_records_are_excluded_by_default_but_historical_traversal_is_cycle_safe_and_project_scoped(): void
    {
        [$project, $actor] = $this->context();
        $root = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'ROOT']);
        $active = Asset::factory()->for($project, 'project')->create(['key' => 'ACTIVE']);
        $inactive = Asset::factory()->for($project, 'project')->create(['key' => 'INACTIVE', 'is_active' => false]);
        $this->edge($project, $actor, $root, $active);
        $inactiveEdge = $this->edge($project, $actor, $root, $inactive);
        $inactiveEdge->update(['is_active' => false]);
        DependencyEdge::query()->create([
            'project_id' => $project->id,
            'source_asset_id' => $active->id,
            'target_asset_id' => $inactive->id,
            'importance' => 'supporting',
            'is_active' => false,
            'created_by' => $actor->id,
        ]);
        DependencyEdge::query()->create([
            'project_id' => $project->id,
            'source_asset_id' => $inactive->id,
            'target_asset_id' => $active->id,
            'importance' => 'supporting',
            'is_active' => false,
            'created_by' => $actor->id,
        ]);
        [$foreign] = $this->context();
        Asset::factory()->for($foreign, 'project')->create(['key' => 'FOREIGN']);
        $graph = app(DependencyGraph::class);

        $this->assertSame(['asset:ACTIVE:1'], $this->keys($graph->dependencies($project, DependencyNode::process($root), transitive: true)));
        $this->assertSame(
            ['asset:ACTIVE:1', 'asset:INACTIVE:1'],
            $this->keys($graph->dependencies($project, DependencyNode::process($root), transitive: true, includeInactive: true)),
        );
    }

    /** @param array<int, mixed> $hits */
    private function keys(array $hits): array
    {
        return array_map(fn ($hit): string => "{$hit->node->type->value}:{$hit->node->key}:{$hit->depth}", $hits);
    }

    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();

        return [$project, $actor];
    }

    private function edge(IsmsProject $project, User $actor, BusinessProcess|Asset $source, BusinessProcess|Asset $target, DependencyImportance $importance = DependencyImportance::Supporting): DependencyEdge
    {
        return DependencyEdge::query()->create([
            'project_id' => $project->id,
            'source_process_id' => $source instanceof BusinessProcess ? $source->id : null,
            'source_asset_id' => $source instanceof Asset ? $source->id : null,
            'target_process_id' => $target instanceof BusinessProcess ? $target->id : null,
            'target_asset_id' => $target instanceof Asset ? $target->id : null,
            'importance' => $importance,
            'is_active' => true,
            'created_by' => $actor->id,
        ]);
    }
}
