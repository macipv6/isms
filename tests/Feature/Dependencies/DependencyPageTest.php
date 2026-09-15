<?php

namespace Tests\Feature\Dependencies;

use App\Enums\ProjectStatus;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DependencyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependency_page_filters_safe_rows_and_returns_bounded_traversal_hits(): void
    {
        [$customer, $project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC-1', 'name' => 'Auftrag']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'APP-1', 'name' => 'CRM']);
        $edge = DependencyEdge::query()->create([
            'project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id,
            'importance' => 'critical', 'reason' => 'Sensitive reason', 'is_active' => true, 'created_by' => $actor->id,
        ]);
        DependencyEdge::factory()->for(IsmsProject::factory()->create(), 'project')->create();

        $query = http_build_query([
            'key' => 'PROC-1', 'state' => 'active', 'source_type' => 'process', 'target_type' => 'asset', 'importance' => 'critical',
            'node_type' => 'process', 'node_key' => 'PROC-1', 'direction' => 'dependencies', 'transitive' => 'true', 'include_inactive' => 'false',
        ]);
        $this->actingAs($actor)->get($this->url($customer, $project).'?'.$query)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('dependencies/Index')
                ->where('filters.source_type', 'process')
                ->where('filters.target_type', 'asset')
                ->where('filters.importance', 'critical')
                ->has('dependencies.data', 1)
                ->where('dependencies.data.0.id', $edge->id)
                ->where('dependencies.data.0.source.key', 'PROC-1')
                ->where('dependencies.data.0.target.key', 'APP-1')
                ->missing('dependencies.data.0.created_by')
                ->where('traversal.selected.key', 'PROC-1')
                ->where('traversal.hits.0.key', 'APP-1')
                ->where('traversal.hits.0.name', 'CRM')
                ->where('traversal.hits.0.depth', 1)
                ->where('traversal.hits.0.importance', 'critical')
                ->where('capabilities.traverse', true));
    }

    public function test_dependency_filters_traversal_and_substitution_are_validated(): void
    {
        [$customer, $project, $actor] = $this->context();
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC-1']);
        $base = $this->url($customer, $project);

        foreach ([
            ['state=maybe', 'state'], ['source_type=other', 'source_type'], ['target_type=other', 'target_type'],
            ['importance=urgent', 'importance'], ['direction=sideways', 'direction'], ['transitive=yes', 'transitive'],
            ['include_inactive=yes', 'include_inactive'], ['node_type=other&node_key=PROC-1', 'node_type'],
        ] as [$query, $field]) {
            $this->from($base)->actingAs($actor)->get($base.'?'.$query)->assertRedirect($base)->assertSessionHasErrors($field);
        }

        $this->actingAs($actor)->get($base.'?node_type=process&node_key=UNKNOWN')->assertNotFound();
        $otherCustomer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $this->actingAs($actor)->get($this->url($otherCustomer, $project))->assertNotFound();
    }

    public function test_read_only_dependency_history_and_traversal_remain_visible_without_write_controls(): void
    {
        [$customer, $project, $actor] = $this->context(status: ProjectStatus::Completed);
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'ASSET']);
        DependencyEdge::query()->create([
            'project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id,
            'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id,
        ]);

        $this->actingAs($actor)->get($this->url($customer, $project).'?node_type=process&node_key=PROC')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('dependencies.data', 1)
                ->has('traversal.hits', 1)
                ->where('capabilities.create', false)
                ->where('capabilities.edit', false)
                ->where('capabilities.changeStatus', false)
                ->where('capabilities.import', false)
                ->where('capabilities.traverse', true));
    }

    /** @return array{Organization, IsmsProject, User} */
    private function context(ProjectStatus $status = ProjectStatus::Draft): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create(['status' => $status]);
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create();

        return [$customer, $project, $actor];
    }

    private function url(Organization $organization, IsmsProject $project): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}/dependencies";
    }
}
