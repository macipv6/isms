<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_isms_projects_table_contains_required_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('isms_projects', [
            'id',
            'organization_id',
            'name',
            'description',
            'framework',
            'approach',
            'bcm_level',
            'status',
            'scope_text',
            'started_at',
            'target_date',
            'completed_at',
            'created_by',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_project_belongs_to_customer_organization_and_creator(): void
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $creator = User::factory()->for($internal)->create();
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
        ]);

        $project = IsmsProject::factory()->for($customer)->create([
            'created_by' => $creator->id,
        ]);

        $this->assertTrue($project->organization->is($customer));
        $this->assertTrue($project->creator->is($creator));
        $this->assertTrue($customer->projects->contains($project));
        $this->assertSame(ProjectStatus::Draft, $project->status);
    }

    public function test_project_defaults_to_bsi_basis_absicherung_and_aufbau_bcms(): void
    {
        $project = IsmsProject::factory()->create();

        $this->assertSame('BSI', $project->framework);
        $this->assertSame('basis_absicherung', $project->approach);
        $this->assertSame('aufbau_bcms', $project->bcm_level);
    }
}
