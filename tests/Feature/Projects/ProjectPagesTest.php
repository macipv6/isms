<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_project_create_page_with_domain_defaults(): void
    {
        $admin = $this->user(UserRole::Admin);
        $customer = $this->customer();

        $this->actingAs($admin)
            ->get('/organizations/'.$customer->id.'/projects/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('projects/Create')
                ->where('organization.id', $customer->id)
                ->where('defaults.framework', 'BSI')
                ->where('defaults.approach', 'basis_absicherung')
                ->where('defaults.bcm_level', 'aufbau_bcms')
                ->where('defaults.status', ProjectStatus::Draft->value));
    }

    public function test_admin_can_open_project_edit_page(): void
    {
        $admin = $this->user(UserRole::Admin);
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create(['name' => 'ISMS 2026']);

        $this->actingAs($admin)
            ->get('/organizations/'.$customer->id.'/projects/'.$project->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('projects/Edit')
                ->where('organization.id', $customer->id)
                ->where('project.id', $project->id)
                ->where('project.name', 'ISMS 2026'));
    }

    public function test_consultant_cannot_open_project_forms(): void
    {
        $consultant = $this->user(UserRole::Consultant);
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create();

        $this->actingAs($consultant)
            ->get('/organizations/'.$customer->id.'/projects/create')
            ->assertForbidden();

        $this->actingAs($consultant)
            ->get('/organizations/'.$customer->id.'/projects/'.$project->id.'/edit')
            ->assertForbidden();
    }

    public function test_project_edit_page_rejects_cross_organization_route_tampering(): void
    {
        $admin = $this->user(UserRole::Admin);
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create();

        $this->actingAs($admin)
            ->get('/organizations/'.$otherCustomer->id.'/projects/'.$project->id.'/edit')
            ->assertNotFound();
    }

    private function user(UserRole $role): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => $role]);
    }

    private function customer(): Organization
    {
        return Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);
    }
}
