<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_project_for_customer_organization(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $response = $this->actingAs($admin)->post('/organizations/'.$customer->id.'/projects', [
            'name' => 'ISMS 2026',
            'description' => 'Einführungsprojekt',
            'framework' => 'BSI',
            'approach' => 'basis_absicherung',
            'bcm_level' => 'aufbau_bcms',
            'status' => ProjectStatus::Draft->value,
            'scope_text' => 'Standort Berlin und zentrale IT-Dienste',
            'started_at' => '2026-09-01',
            'target_date' => '2027-03-31',
            'completed_at' => null,
        ]);

        $project = IsmsProject::query()->where('name', 'ISMS 2026')->sole();

        $response->assertRedirect('/organizations/'.$customer->id);
        $this->assertSame($customer->id, $project->organization_id);
        $this->assertSame($admin->id, $project->created_by);
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertSame('BSI', $project->framework);
    }

    public function test_admin_can_update_project_lifecycle_fields(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create([
            'created_by' => $admin->id,
            'name' => 'ISMS Entwurf',
            'status' => ProjectStatus::Draft,
        ]);

        $this->actingAs($admin)
            ->put('/organizations/'.$customer->id.'/projects/'.$project->id, [
                'name' => 'ISMS 2026',
                'description' => 'Aktualisierte Beschreibung',
                'framework' => 'BSI',
                'approach' => 'basis_absicherung',
                'bcm_level' => 'aufbau_bcms',
                'status' => ProjectStatus::Active->value,
                'scope_text' => 'Aktualisierter Scope',
                'started_at' => '2026-09-01',
                'target_date' => '2027-03-31',
                'completed_at' => null,
            ])
            ->assertRedirect('/organizations/'.$customer->id);

        $project->refresh();
        $this->assertSame('ISMS 2026', $project->name);
        $this->assertSame(ProjectStatus::Active, $project->status);
        $this->assertSame('Aktualisierter Scope', $project->scope_text);
    }

    public function test_project_payload_is_validated_and_tenant_keys_are_prohibited(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        $otherCreator = $this->admin();

        $this->actingAs($admin)
            ->post('/organizations/'.$customer->id.'/projects', [
                'name' => '',
                'framework' => 'ISO27001',
                'approach' => 'standard_absicherung',
                'bcm_level' => 'standard_bcms',
                'status' => 'unknown',
                'started_at' => '2026-09-10',
                'target_date' => '2026-09-01',
                'organization_id' => $otherCustomer->id,
                'created_by' => $otherCreator->id,
            ])
            ->assertSessionHasErrors([
                'name',
                'framework',
                'approach',
                'bcm_level',
                'status',
                'target_date',
                'organization_id',
                'created_by',
            ]);

        $this->assertDatabaseCount('isms_projects', 0);
    }

    private function admin(): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => UserRole::Admin]);
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
