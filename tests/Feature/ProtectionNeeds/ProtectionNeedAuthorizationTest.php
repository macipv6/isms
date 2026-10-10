<?php

namespace Tests\Feature\ProtectionNeeds;

use App\Enums\RegisterImportKind;
use App\Enums\UserRole;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProtectionNeed;
use App\Models\ProtectionNeedDowngradeRequest;
use App\Models\RegisterImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProtectionNeedAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_protection_need_returns_not_found_before_validation(): void
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $foreign = ProtectionNeed::factory()->create();
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Consultant]);

        $this->actingAs($actor)->post("/organizations/{$organization->id}/projects/{$project->id}/protection-needs/{$foreign->id}/downgrades", [])->assertNotFound();
    }

    public function test_customer_user_cannot_view_protection_needs(): void
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $actor = User::factory()->for($organization)->create(['role' => UserRole::Admin]);
        $this->actingAs($actor)->get("/organizations/{$organization->id}/projects/{$project->id}/protection-needs")->assertForbidden();
    }

    public function test_inactive_state_filter_returns_only_historical_targets(): void
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $active = BusinessProcess::factory()->for($project, 'project')->create(['is_active' => true]);
        $inactive = BusinessProcess::factory()->for($project, 'project')->create(['is_active' => false]);
        ProtectionNeed::factory()->create(['project_id' => $project->id, 'business_process_id' => $active->id]);
        ProtectionNeed::factory()->create(['project_id' => $project->id, 'business_process_id' => $inactive->id]);
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Consultant]);

        $this->actingAs($actor)->get("/organizations/{$organization->id}/projects/{$project->id}/protection-needs?state=inactive")
            ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('protection-needs/Index', false)
            ->has('needs.data', 1)
            ->where('needs.data.0.targetId', $inactive->id));
    }

    public function test_downgrade_routes_reject_actions_for_the_other_route(): void
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $need = ProtectionNeed::factory()->create(['project_id' => $project->id, 'business_process_id' => $process->id]);
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $actor = User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
        $requester = User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
        $downgrade = ProtectionNeedDowngradeRequest::query()->create([
            'project_id' => $project->id, 'protection_need_id' => $need->id,
            'inherited_level' => 'high', 'requested_level' => 'normal', 'rationale' => 'Beantragt',
            'requested_by' => $requester->id, 'status' => 'pending',
        ]);
        $base = "/organizations/{$organization->id}/projects/{$project->id}";

        $this->actingAs($actor)->post("{$base}/protection-needs/{$need->id}/downgrades", [
            'action' => 'internal', 'approved' => true, 'rationale' => 'Falsche Route',
        ])->assertSessionHasErrors('action');
        $this->actingAs($actor)->post("{$base}/downgrades/{$downgrade->id}/decision", [
            'action' => 'request', 'requested_level' => 'normal', 'rationale' => 'Falsche Route',
        ])->assertSessionHasErrors('action');
    }

    public function test_protection_need_import_history_requires_current_active_internal_membership(): void
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $actor = User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
        $batch = RegisterImportBatch::factory()->create(['project_id' => $project->id, 'kind' => RegisterImportKind::ProtectionNeeds, 'created_by' => $actor->id]);
        $url = "/organizations/{$organization->id}/projects/{$project->id}/imports/{$batch->id}";

        $internal->update(['is_active' => false]);
        $this->actingAs($actor)->get($url)->assertForbidden();
        $internal->update(['is_active' => true]);
        DB::table('users')->where('id', $actor->id)->update(['role' => 'customer']);
        $this->actingAs($actor)->get($url)->assertForbidden();
    }

    public function test_foreign_downgrade_and_import_batch_are_not_found(): void
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Consultant]);
        $foreignNeed = ProtectionNeed::factory()->create();
        $downgrade = ProtectionNeedDowngradeRequest::query()->create([
            'project_id' => $foreignNeed->project_id, 'protection_need_id' => $foreignNeed->id,
            'inherited_level' => 'high', 'requested_level' => 'normal', 'rationale' => 'Fremd',
            'requested_by' => $actor->id, 'status' => 'pending',
        ]);
        $batch = RegisterImportBatch::factory()->create(['project_id' => $foreignNeed->project_id, 'kind' => RegisterImportKind::ProtectionNeeds]);
        $base = "/organizations/{$organization->id}/projects/{$project->id}";

        $this->actingAs($actor)->post("{$base}/downgrades/{$downgrade->id}/decision", [])->assertNotFound();
        $this->actingAs($actor)->get("{$base}/imports/{$batch->id}")->assertNotFound();
        $this->actingAs($actor)->post("{$base}/imports/{$batch->id}/confirm", [])->assertNotFound();
    }

    public function test_protection_need_import_page_redacts_internal_fingerprint(): void
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Consultant]);
        $batch = RegisterImportBatch::factory()->create([
            'project_id' => $project->id, 'kind' => RegisterImportKind::ProtectionNeeds, 'created_by' => $actor->id,
            'summary' => ['counts' => ['new' => 1], 'state_fingerprint' => 'internal-state-fingerprint'],
        ]);

        $this->actingAs($actor)->get("/organizations/{$organization->id}/projects/{$project->id}/protection-needs?batch={$batch->id}")
            ->assertOk()->assertDontSee('internal-state-fingerprint');
        $this->actingAs($actor)->get("/organizations/{$organization->id}/projects/{$project->id}/imports/{$batch->id}")
            ->assertOk()->assertDontSee('internal-state-fingerprint');
    }

    public function test_protection_need_approval_requires_versions_with_german_error(): void
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Consultant]);

        $this->actingAs($actor)->post("/organizations/{$organization->id}/projects/{$project->id}/protection-needs/targets/process/{$process->id}/approve", [])
            ->assertSessionHasErrors(['expected_versions' => 'Dieses Feld ist erforderlich.']);
    }
}
