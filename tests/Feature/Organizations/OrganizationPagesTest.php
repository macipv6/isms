<?php

namespace Tests\Feature\Organizations;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_customer_create_and_edit_pages(): void
    {
        $admin = $this->user(UserRole::Admin);
        $customer = $this->customer();

        $this->actingAs($admin)
            ->get('/organizations/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('organizations/Create'));

        $this->actingAs($admin)
            ->get('/organizations/'.$customer->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('organizations/Edit')
                ->where('organization.id', $customer->id));
    }

    public function test_consultant_sees_read_only_customer_page_with_projects(): void
    {
        $consultant = $this->user(UserRole::Consultant);
        $customer = $this->customer(['name' => 'Muster GmbH']);
        $project = IsmsProject::factory()->for($customer)->create([
            'name' => 'ISMS 2026',
            'status' => ProjectStatus::Active,
        ]);

        $this->actingAs($consultant)
            ->get('/organizations/'.$customer->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('organizations/Show')
                ->where('organization.id', $customer->id)
                ->where('canManage', false)
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->where('projects.0.status', ProjectStatus::Active->value)
                ->where('projects.0.assessment_started', false)
                ->where('projects.0.can_assess', true)
                ->where(
                    'projects.0.assessment_url',
                    '/organizations/'.$customer->id.'/projects/'.$project->id.'/assessment',
                )
                ->where('projects.0.assessment_progress', null));
    }

    public function test_consultant_cannot_open_customer_create_or_edit_page(): void
    {
        $consultant = $this->user(UserRole::Consultant);
        $customer = $this->customer();

        $this->actingAs($consultant)
            ->get('/organizations/create')
            ->assertForbidden();

        $this->actingAs($consultant)
            ->get('/organizations/'.$customer->id.'/edit')
            ->assertForbidden();
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
            ...$attributes,
        ]);
    }
}
