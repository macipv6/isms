<?php

namespace Tests\Feature\Findings;

use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Finding;
use App\Models\Measure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingAuthorizationTest extends TestCase
{
    use InteractsWithFindingWorkflow;
    use RefreshDatabase;

    public function test_active_internal_admins_and_consultants_can_use_the_http_workflow(): void
    {
        foreach ([UserRole::Admin, UserRole::Consultant] as $role) {
            [$organization, $project, $assessment, $question] = $this->findingContext();
            $actor = $this->internalUser($role);

            $this->actingAs($actor)
                ->post($this->findingStoreUrl($organization, $project, $question), $this->findingPayload())
                ->assertRedirect()
                ->assertSessionHas('success', 'Feststellung vorgeschlagen.');
            $finding = Finding::query()->where('project_id', $project->id)->sole();

            $this->actingAs($actor)
                ->put($this->findingUrl($organization, $project, $finding), $this->findingPayload(['title' => 'Geändert']))
                ->assertSessionHas('success', 'Feststellung aktualisiert.');
            $this->actingAs($actor)
                ->patch($this->findingUrl($organization, $project, $finding, '/decision'), ['status' => FindingStatus::Rejected->value, 'decision_note' => 'Nicht bestätigt'])
                ->assertSessionHas('success', 'Entscheidung gespeichert.');
        }
    }

    public function test_guests_customer_users_and_read_only_contexts_cannot_write(): void
    {
        [$organization, $project, $assessment, $question] = $this->findingContext();
        $url = $this->findingStoreUrl($organization, $project, $question);

        $this->post($url, $this->findingPayload())->assertRedirect('/login');
        $customerUser = \App\Models\User::factory()->for($organization)->create(['role' => UserRole::Admin]);
        $this->actingAs($customerUser)->post($url, $this->findingPayload())->assertForbidden();

        foreach ([ProjectStatus::Completed, ProjectStatus::Archived] as $status) {
            [$readOnlyOrganization, $readOnlyProject, $assessment, $readOnlyQuestion, $actor] = $this->findingContext(projectAttributes: ['status' => $status]);
            $this->actingAs($actor)
                ->post($this->findingStoreUrl($readOnlyOrganization, $readOnlyProject, $readOnlyQuestion), $this->findingPayload())
                ->assertForbidden();
        }

        [$inactiveOrganization, $inactiveProject, $assessment, $inactiveQuestion, $actor] = $this->findingContext();
        $inactiveOrganization->update(['is_active' => false]);
        $this->actingAs($actor)
            ->post($this->findingStoreUrl($inactiveOrganization, $inactiveProject, $inactiveQuestion), $this->findingPayload())
            ->assertForbidden();
        $this->assertDatabaseCount('findings', 0);
    }

    public function test_authorized_close_endpoint_closes_an_accepted_finding_with_terminal_measures(): void
    {
        [$organization, $project, $assessment, $question, $actor] = $this->findingContext();
        $finding = Finding::factory()->for($project)->create([
            'status' => FindingStatus::Accepted,
            'proposed_by' => $actor->id,
        ]);
        Measure::factory()->for($project)->for($finding)->create(['status' => MeasureStatus::Completed]);

        $this->actingAs($actor)
            ->patch($this->findingUrl($organization, $project, $finding, '/close'))
            ->assertRedirect()
            ->assertSessionHas('success', 'Feststellung geschlossen.');

        $this->assertSame(FindingStatus::Closed, $finding->fresh()->status);
    }

    public function test_nested_substitution_returns_404_before_authorization_or_write(): void
    {
        [$organization, $project, $assessment, $question, $actor] = $this->findingContext();
        [$foreignOrganization, $foreignProject, $foreignAssessment, $foreignQuestion] = $this->findingContext();
        $finding = Finding::factory()->for($foreignProject)->create();

        $this->actingAs($actor)->post($this->findingStoreUrl($foreignOrganization, $project, $question), $this->findingPayload())->assertNotFound();
        $this->actingAs($actor)->post($this->findingStoreUrl($organization, $project, $foreignQuestion), $this->findingPayload())->assertNotFound();
        $this->actingAs($actor)->put($this->findingUrl($organization, $project, $finding), $this->findingPayload())->assertNotFound();
        $this->actingAs($actor)->patch($this->findingUrl($organization, $project, $finding, '/decision'), ['status' => 'accepted'])->assertNotFound();
        $this->actingAs($actor)->patch($this->findingUrl($organization, $project, $finding, '/close'))->assertNotFound();
        $this->assertDatabaseCount('findings', 1);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_http_validation_is_bounded_and_rejection_requires_a_note(): void
    {
        [$organization, $project, $assessment, $question, $actor] = $this->findingContext();
        $url = $this->findingStoreUrl($organization, $project, $question);

        $this->actingAs($actor)->post($url, [])->assertSessionHasErrors(['title', 'description', 'severity']);
        $this->actingAs($actor)->post($url, $this->findingPayload(['title' => str_repeat('a', 256)]))->assertSessionHasErrors('title');
        $this->actingAs($actor)->post($url, $this->findingPayload(['description' => str_repeat('a', 10001)]))->assertSessionHasErrors('description');
        $this->actingAs($actor)->post($url, $this->findingPayload(['severity' => 'urgent']))->assertSessionHasErrors('severity');

        $this->actingAs($actor)->post($url, $this->findingPayload());
        $finding = Finding::query()->where('project_id', $project->id)->sole();
        $this->actingAs($actor)
            ->patch($this->findingUrl($organization, $project, $finding, '/decision'), ['status' => 'rejected'])
            ->assertSessionHasErrors('decision_note');
        $this->assertSame(FindingStatus::Proposed, $finding->fresh()->status);
    }
}
