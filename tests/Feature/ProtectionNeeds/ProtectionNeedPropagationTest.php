<?php

namespace Tests\Feature\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProtectionNeed;
use App\Models\User;
use App\Services\Dependencies\DependencyService;
use App\Services\ProtectionNeeds\ProtectionNeedPropagator;
use App\Services\ProtectionNeeds\ProtectionNeedService;
use App\Services\Registers\AssetService;
use App\Services\Registers\BusinessProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtectionNeedPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_maximum_transitive_propagation_tracks_tied_origins_and_excludes_inactive_edges(): void
    {
        $project = IsmsProject::factory()->for(Organization::factory()->state(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $p1 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $p2 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        $a1 = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        $a2 = Asset::factory()->for($project, 'project')->create(['key' => 'A2']);
        $edge1 = $this->edge($project, $actor, $p1, $a1);
        $this->edge($project, $actor, $p2, $a1);
        $this->edge($project, $actor, $a1, $a2);
        $service = app(ProtectionNeedService::class);
        foreach ([$p1, $p2] as $process) {
            $node = DependencyNode::process($process);
            $service->rateTarget($project, $node, [
                'confidentiality' => ['level' => 'very_high', 'rationale' => 'Kritisch'],
                'integrity' => ['level' => 'normal', 'rationale' => 'Prüfbar'],
                'availability' => ['level' => 'normal', 'rationale' => 'Ersatz'],
            ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        }
        $need = ProtectionNeed::query()->where('asset_id', $a2->id)->where('objective', 'confidentiality')->firstOrFail();
        $this->assertSame('very_high', $need->effective_level->value);
        $this->assertSame([$p1->id, $p2->id], $need->sources()->orderBy('origin_process_id')->pluck('origin_process_id')->all());
        $edge1->update(['is_active' => false]);
        app(ProtectionNeedPropagator::class)->recalculate($project, [DependencyNode::asset($a1)]);
        $this->assertSame([$p2->id], $need->sources()->pluck('origin_process_id')->all());
    }

    public function test_register_and_dependency_mutations_initialize_and_recalculate_in_their_transaction(): void
    {
        $project = IsmsProject::factory()->for(Organization::factory()->state(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $process = app(BusinessProcessService::class)->create($project, ['key' => 'P1', 'name' => 'Process'], $actor);
        $asset = app(AssetService::class)->create($project, ['key' => 'A1', 'name' => 'Asset', 'type' => 'information'], $actor);
        $this->assertSame(6, ProtectionNeed::query()->where('project_id', $project->id)->count());
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::process($process), [
            'confidentiality' => ['level' => 'high', 'rationale' => 'Kritisch'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Prüfbar'],
            'availability' => ['level' => 'normal', 'rationale' => 'Ersatz'],
        ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        $edge = app(DependencyService::class)->create($project, ['source_type' => 'process', 'source_key' => 'P1', 'target_type' => 'asset', 'target_key' => 'A1', 'importance' => 'supporting', 'reason' => null], $actor);
        $need = ProtectionNeed::query()->where('asset_id', $asset->id)->where('objective', 'confidentiality')->firstOrFail();
        $this->assertSame('high', $need->effective_level->value);
        app(DependencyService::class)->changeStatus($edge, false, $actor);
        $this->assertNull($need->fresh()->inherited_level);
    }

    public function test_diamond_tie_chooses_stable_first_edge_for_one_origin(): void
    {
        $project = IsmsProject::factory()->for(Organization::factory()->state(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $left = Asset::factory()->for($project, 'project')->create();
        $right = Asset::factory()->for($project, 'project')->create();
        $end = Asset::factory()->for($project, 'project')->create();
        $this->edge($project, $actor, $process, $left);
        $this->edge($project, $actor, $process, $right);
        $leftEdge = $this->edge($project, $actor, $left, $end);
        $rightEdge = $this->edge($project, $actor, $right, $end);
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::process($process), ['confidentiality' => ['level' => 'high', 'rationale' => 'Kritisch']], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        $need = ProtectionNeed::query()->where('asset_id', $end->id)->where('objective', 'confidentiality')->firstOrFail();
        $this->assertSame(1, $need->sources()->count());
        $this->assertSame(min($leftEdge->id, $rightEdge->id), $need->sources()->firstOrFail()->first_edge_id);
    }

    private function edge(IsmsProject $project, User $actor, BusinessProcess|Asset $source, Asset $target): DependencyEdge
    {
        return DependencyEdge::query()->create([
            'project_id' => $project->id,
            'source_process_id' => $source instanceof BusinessProcess ? $source->id : null,
            'source_asset_id' => $source instanceof Asset ? $source->id : null,
            'target_asset_id' => $target->id,
            'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id,
        ]);
    }
}
