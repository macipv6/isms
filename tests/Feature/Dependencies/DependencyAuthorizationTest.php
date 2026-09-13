<?php

namespace Tests\Feature\Dependencies;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DependencyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_internal_admin_and_consultant_can_write_and_read_graphs(): void
    {
        foreach ([UserRole::Admin, UserRole::Consultant] as $role) {
            [$organization, $project, $actor] = $this->context(role: $role);
            BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC']);
            Asset::factory()->for($project, 'project')->create(['key' => 'ASSET']);
            $this->actingAs($actor)->post($this->collectionUrl($organization, $project), $this->payload())->assertRedirect();
            $this->actingAs($actor)->get($this->graphUrl($organization, $project, 'process', 'PROC').'?direction=dependencies&transitive=true&include_inactive=false')->assertOk();
        }
    }

    #[DataProvider('readOnlyStates')]
    public function test_read_only_projects_forbid_writes_but_keep_historical_graph_reads(bool $customerActive, string $status): void
    {
        [$organization, $project, $actor] = $this->context(customerActive: $customerActive, status: $status);
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC']);
        Asset::factory()->for($project, 'project')->create(['key' => 'ASSET']);

        $this->actingAs($actor)->post($this->collectionUrl($organization, $project), $this->payload())->assertForbidden();
        $this->actingAs($actor)->get($this->graphUrl($organization, $project, 'process', 'PROC'))->assertOk();
    }

    public static function readOnlyStates(): array
    {
        return [[false, 'draft'], [true, 'completed'], [true, 'archived']];
    }

    public function test_forbidden_role_returns_403_and_nested_substitution_returns_404(): void
    {
        [$organization, $project, $actor] = $this->context(actorOrganizationType: 'customer');
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC']);
        Asset::factory()->for($project, 'project')->create(['key' => 'ASSET']);
        $this->actingAs($actor)->post($this->collectionUrl($organization, $project), $this->payload())->assertForbidden();

        [$foreignOrganization, $foreignProject, $internal] = $this->context();
        $foreignProcess = BusinessProcess::factory()->for($foreignProject, 'project')->create(['key' => 'FOREIGN-P']);
        $foreignAsset = Asset::factory()->for($foreignProject, 'project')->create(['key' => 'FOREIGN-A']);
        $edge = $this->edge($foreignProject, $internal, $foreignProcess, $foreignAsset);
        $this->actingAs($internal)->post($this->collectionUrl($foreignOrganization, $project), $this->payload())->assertNotFound();
        $this->actingAs($internal)->put($this->edgeUrl($organization, $project, $edge), ['importance' => 'critical', 'reason' => null, 'updated_at' => $edge->updated_at->toIso8601String()])->assertNotFound();
        $this->actingAs($internal)->post($this->collectionUrl($organization, $project), [
            ...$this->payload(),
            'source_key' => 'FOREIGN-P',
            'target_key' => 'FOREIGN-A',
        ])->assertNotFound();
    }

    public function test_graph_query_values_and_node_type_are_validated_server_side(): void
    {
        [$organization, $project, $actor] = $this->context();
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC']);

        $this->actingAs($actor)->get($this->graphUrl($organization, $project, 'process', 'PROC').'?direction=sideways')->assertSessionHasErrors('direction');
        $this->actingAs($actor)->get($this->graphUrl($organization, $project, 'invalid', 'PROC'))->assertNotFound();
    }

    private function context(string $actorOrganizationType = 'internal', bool $customerActive = true, string $status = 'draft', UserRole $role = UserRole::Consultant): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null, 'is_active' => $customerActive]);
        $project = IsmsProject::factory()->for($customer)->create(['status' => $status]);
        $actorOrganization = Organization::factory()->create(['organization_type' => $actorOrganizationType, 'entra_tenant_id' => $actorOrganizationType === 'customer' ? null : fake()->uuid()]);
        $actor = User::factory()->for($actorOrganization)->create(['role' => $role]);

        return [$customer, $project, $actor];
    }

    private function payload(): array
    {
        return ['source_type' => 'process', 'source_key' => 'PROC', 'target_type' => 'asset', 'target_key' => 'ASSET', 'importance' => 'supporting', 'reason' => null];
    }

    private function collectionUrl(Organization $organization, IsmsProject $project): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}/dependencies";
    }

    private function edgeUrl(Organization $organization, IsmsProject $project, DependencyEdge $edge): string
    {
        return $this->collectionUrl($organization, $project)."/{$edge->id}";
    }

    private function graphUrl(Organization $organization, IsmsProject $project, string $type, string $key): string
    {
        return $this->collectionUrl($organization, $project)."/{$type}/{$key}/graph";
    }

    private function edge(IsmsProject $project, User $actor, BusinessProcess $source, Asset $target): DependencyEdge
    {
        return DependencyEdge::query()->create(['project_id' => $project->id, 'source_process_id' => $source->id, 'target_asset_id' => $target->id, 'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id]);
    }
}
