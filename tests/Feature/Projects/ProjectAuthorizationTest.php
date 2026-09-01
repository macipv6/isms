<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultant_cannot_create_project(): void
    {
        $consultant = $this->user(UserRole::Consultant);
        $customer = $this->customer();

        $this->actingAs($consultant)
            ->post('/organizations/'.$customer->id.'/projects', $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('isms_projects', 0);
    }

    public function test_consultant_cannot_update_project(): void
    {
        $consultant = $this->user(UserRole::Consultant);
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create();

        $this->actingAs($consultant)
            ->put('/organizations/'.$customer->id.'/projects/'.$project->id, [
                ...$this->validPayload(),
                'name' => 'Manipuliert',
            ])
            ->assertForbidden();

        $this->assertNotSame('Manipuliert', $project->fresh()->name);
    }

    public function test_project_cannot_be_updated_through_different_organization_route(): void
    {
        $admin = $this->user(UserRole::Admin);
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create(['name' => 'Original']);

        $this->actingAs($admin)
            ->put('/organizations/'.$otherCustomer->id.'/projects/'.$project->id, [
                ...$this->validPayload(),
                'name' => 'Mandantenwechsel',
            ])
            ->assertNotFound();

        $this->assertSame($customer->id, $project->fresh()->organization_id);
        $this->assertSame('Original', $project->fresh()->name);
    }

    public function test_internal_organization_cannot_receive_customer_project(): void
    {
        $admin = $this->user(UserRole::Admin);
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        $this->actingAs($admin)
            ->post('/organizations/'.$internal->id.'/projects', $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('isms_projects', 0);
    }

    public function test_inactive_customer_cannot_receive_new_project(): void
    {
        $admin = $this->user(UserRole::Admin);
        $customer = $this->customer(['is_active' => false]);

        $this->actingAs($admin)
            ->post('/organizations/'.$customer->id.'/projects', $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('isms_projects', 0);
    }

    /**
     * @return array<string, string|null>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'ISMS 2026',
            'description' => null,
            'framework' => 'BSI',
            'approach' => 'basis_absicherung',
            'bcm_level' => 'aufbau_bcms',
            'status' => ProjectStatus::Draft->value,
            'scope_text' => 'Unternehmensweiter Scope',
            'started_at' => null,
            'target_date' => null,
            'completed_at' => null,
        ];
    }

    private function user(UserRole $role): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => $role]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Organization
    {
        return Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
