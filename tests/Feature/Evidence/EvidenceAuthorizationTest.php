<?php

namespace Tests\Feature\Evidence;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\AssessmentStarter;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_and_customer_users_cannot_view_evidence_history(): void
    {
        [$organization, $project, $question, $evidence] = $this->context();

        $this->get($this->downloadUrl($organization, $project, $evidence))->assertRedirect('/login');
        $this->actingAs(User::factory()->for($organization)->create(['role' => UserRole::Admin]))
            ->get($this->downloadUrl($organization, $project, $evidence))->assertForbidden();
        $this->actingAs($this->internal(UserRole::Consultant)->forceFill(['is_active' => false]))
            ->get($this->downloadUrl($organization, $project, $evidence))->assertRedirect('/login');
    }

    public function test_read_history_is_available_to_active_internal_roles_but_writes_are_frozen_for_read_only_contexts(): void
    {
        foreach ([ProjectStatus::Completed, ProjectStatus::Archived] as $status) {
            [$organization, $project, $question, $evidence] = $this->context(['status' => $status]);
            $actor = $this->internal(UserRole::Consultant);

            $this->actingAs($actor)->get($this->downloadUrl($organization, $project, $evidence))->assertOk();
            $this->actingAs($actor)->patch($this->reviewUrl($organization, $project, $evidence), ['status' => 'verified'])->assertForbidden();
            $this->actingAs($actor)->post($this->questionUrl($organization, $project, $question), [])->assertForbidden();
            $this->assertDatabaseMissing('audit_events', ['event_type' => 'evidence.reviewed']);
            $this->assertDatabaseCount('evidence_question_links', 0);
            $this->assertCount(1, \Illuminate\Support\Facades\Storage::disk('evidence')->allFiles());
        }

        [$organization, $project, $question, $evidence] = $this->context();
        $organization->update(['is_active' => false]);
        $this->actingAs($this->internal(UserRole::Admin))->get($this->downloadUrl($organization, $project, $evidence))->assertOk();
    }

    public function test_nested_parent_and_resource_substitutions_return_404_before_authorization_or_writes(): void
    {
        [$organization, $project, $question, $evidence] = $this->context();
        [$foreignOrganization, $foreignProject, $foreignQuestion, $foreignEvidence] = $this->context();
        $foreignFinding = Finding::factory()->for($foreignProject)->create();
        $actor = $this->internal(UserRole::Admin);

        $this->actingAs($actor)->post($this->questionUrl($foreignOrganization, $project, $question), [])->assertNotFound();
        $this->actingAs($actor)->post($this->questionUrl($organization, $project, $foreignQuestion), [])->assertNotFound();
        $this->actingAs($actor)->get($this->downloadUrl($organization, $project, $foreignEvidence))->assertNotFound();
        $this->actingAs($actor)->post($this->findingUrl($organization, $project, $foreignFinding, $evidence), [])->assertNotFound();
        $this->assertDatabaseCount('evidence_question_links', 0);
        $this->assertDatabaseCount('evidence_finding_links', 0);
    }

    /** @return array{Organization, IsmsProject, \App\Models\AssessmentQuestion, EvidenceFile} */
    private function context(array $projectAttributes = []): array
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create($projectAttributes);
        $actor = $this->internal(UserRole::Consultant);
        $question = app(AssessmentStarter::class)->start($project, $actor)->questions()->firstOrFail();
        $evidence = EvidenceFile::factory()->for($project)->create(['storage_path' => 'projects/'.$project->id.'/history.txt', 'size_bytes' => 7, 'sha256' => hash('sha256', 'history')]);
        \Illuminate\Support\Facades\Storage::fake('evidence');
        \Illuminate\Support\Facades\Storage::disk('evidence')->put($evidence->storage_path, 'history');

        return [$organization, $project, $question, $evidence];
    }

    private function internal(UserRole $role): User
    {
        return User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => $role]);
    }

    private function questionUrl(Organization $organization, IsmsProject $project, object $question): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/assessment/questions/'.$question->id.'/evidence';
    }

    private function reviewUrl(Organization $organization, IsmsProject $project, EvidenceFile $evidence): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/evidence/'.$evidence->id.'/review';
    }

    private function downloadUrl(Organization $organization, IsmsProject $project, EvidenceFile $evidence): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/evidence/'.$evidence->id.'/download';
    }

    private function findingUrl(Organization $organization, IsmsProject $project, Finding $finding, EvidenceFile $evidence): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/findings/'.$finding->id.'/evidence/'.$evidence->id;
    }
}
