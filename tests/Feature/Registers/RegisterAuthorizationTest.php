<?php

namespace Tests\Feature\Registers;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_internal_admin_and_consultant_can_create_processes(): void
    {
        foreach ([UserRole::Admin, UserRole::Consultant] as $role) {
            [$organization, $project, $actor] = $this->context(role: $role);
            $this->actingAs($actor)->post($this->processUrl($organization, $project), $this->processPayload())->assertRedirect();
        }
        $this->assertDatabaseCount('business_processes', 2);
    }

    public function test_known_but_forbidden_process_operation_returns_403(): void
    {
        [$organization, $project, $actor] = $this->context(actorOrganizationType: 'customer');
        $this->actingAs($actor)->post($this->processUrl($organization, $project), $this->processPayload())->assertForbidden();
    }

    public function test_inactive_internal_user_is_rejected_by_the_active_user_middleware(): void
    {
        [$organization, $project, $actor] = $this->context(active: false);
        $this->actingAs($actor)->post($this->processUrl($organization, $project), $this->processPayload())->assertRedirect('/login');
    }

    #[DataProvider('readOnlyStates')]
    public function test_inactive_customer_and_read_only_projects_forbid_writes(bool $customerActive, string $status): void
    {
        [$organization, $project, $actor] = $this->context(customerActive: $customerActive, status: $status);
        $this->actingAs($actor)->post($this->assetUrl($organization, $project), $this->assetPayload())->assertForbidden();
    }

    public static function readOnlyStates(): array { return [[false, 'draft'], [true, 'completed'], [true, 'archived']]; }

    public function test_nested_organization_project_and_record_substitution_return_404(): void
    {
        [$organization, $project, $actor] = $this->context();
        [$foreignOrganization, $foreignProject] = $this->context();
        $asset = Asset::factory()->for($foreignProject)->create();
        $this->actingAs($actor)->post($this->processUrl($foreignOrganization, $project), $this->processPayload())->assertNotFound();
        $this->actingAs($actor)->put($this->assetUrl($organization, $project, $asset), $this->assetPayload())->assertNotFound();
    }

    private function context(string $actorOrganizationType = 'internal', bool $active = true, bool $customerActive = true, string $status = 'draft', UserRole $role = UserRole::Consultant): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null, 'is_active' => $customerActive]);
        $project = IsmsProject::factory()->for($customer)->create(['status' => $status]);
        $actorOrganization = Organization::factory()->create(['organization_type' => $actorOrganizationType, 'entra_tenant_id' => $actorOrganizationType === 'customer' ? null : fake()->uuid()]);
        $actor = User::factory()->for($actorOrganization)->create(['is_active' => $active, 'role' => $role]);
        return [$customer, $project, $actor];
    }

    private function processUrl(Organization $organization, IsmsProject $project): string { return "/organizations/{$organization->id}/projects/{$project->id}/processes"; }
    private function assetUrl(Organization $organization, IsmsProject $project, ?Asset $asset = null): string { return "/organizations/{$organization->id}/projects/{$project->id}/assets".($asset ? "/{$asset->id}" : ''); }
    private function processPayload(): array { return ['key' => 'SALES', 'name' => 'Sales', 'active' => true]; }
    private function assetPayload(): array { return ['key' => 'CRM', 'name' => 'CRM', 'type' => 'application', 'active' => true]; }
}
