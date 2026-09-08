<?php

namespace Tests\Feature\WorkItems;

use App\Enums\EvidenceReviewStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\MeasurePriority;
use App\Enums\MeasureStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkItemRegisterPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_are_project_scoped_and_expose_safe_deep_link_data(): void
    {
        [$customer, $project, $finding, $actor] = $this->context();
        $question = $finding->question;
        $evidence = EvidenceFile::factory()->for($project)->create([
            'original_name' => 'Richtlinie.pdf',
            'status' => EvidenceReviewStatus::Verified,
        ]);
        $question->evidenceFiles()->attach($evidence->id, [
            'id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'project_assessment_id' => $finding->project_assessment_id,
        ]);
        $measure = Measure::factory()->for($project)->for($finding)->create([
            'title' => 'Berechtigungen prüfen',
            'priority' => MeasurePriority::High,
            'status' => MeasureStatus::InProgress,
        ]);

        $foreignProject = IsmsProject::factory()->create();
        EvidenceFile::factory()->for($foreignProject)->create(['original_name' => 'fremd.pdf']);
        $foreignFinding = Finding::factory()->for($foreignProject)->create(['title' => 'Fremde Feststellung']);
        Measure::factory()->for($foreignProject)->for($foreignFinding)->create(['title' => 'Fremde Maßnahme']);

        $this->actingAs($actor)->get($this->url($customer, $project, 'evidence'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('evidence/Index')
                ->where('canManage', true)
                ->has('evidence', 1)
                ->where('evidence.0.id', $evidence->id)
                ->where('evidence.0.questions.0.id', $question->id)
                ->where('evidence.0.questions.0.question_key', $question->question_key)
                ->missing('evidence.0.storage_path')
                ->missing('evidence.0.sha256'));

        $this->actingAs($actor)->get($this->url($customer, $project, 'findings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('findings/Index')
                ->where('canManage', true)
                ->has('findings', 1)
                ->where('findings.0.id', $finding->id)
                ->where('findings.0.question_id', $question->id)
                ->where('findings.0.question_key', $question->question_key)
                ->where('findings.0.measures.total', 1)
                ->where('findings.0.measures.terminal', 0));

        $this->actingAs($actor)->get($this->url($customer, $project, 'measures'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('measures/Index')
                ->where('canManage', true)
                ->has('measures', 1)
                ->where('measures.0.id', $measure->id)
                ->where('measures.0.finding.id', $finding->id)
                ->where('measures.0.finding.question_id', $question->id)
                ->where('measures.0.finding.question_key', $question->question_key));
    }

    public function test_each_register_validates_and_applies_its_status_filter(): void
    {
        [$customer, $project, $finding, $actor] = $this->context();
        EvidenceFile::factory()->for($project)->create(['status' => EvidenceReviewStatus::Verified]);
        EvidenceFile::factory()->for($project)->create(['status' => EvidenceReviewStatus::Rejected]);
        Finding::factory()->for($project)->create(['status' => FindingStatus::Rejected]);
        Measure::factory()->for($project)->for($finding)->create(['status' => MeasureStatus::Completed]);
        Measure::factory()->for($project)->for($finding)->create(['status' => MeasureStatus::Blocked]);

        foreach ([
            ['evidence', 'verified', 'evidence'],
            ['findings', 'accepted', 'findings'],
            ['measures', 'completed', 'measures'],
        ] as [$register, $status, $prop]) {
            $url = $this->url($customer, $project, $register);
            $this->actingAs($actor)->get($url.'?status='.$status)
                ->assertOk()
                ->assertInertia(fn (Assert $page): Assert => $page
                    ->where('filters.status', $status)
                    ->has($prop, 1));

            $this->from($url)->actingAs($actor)->get($url.'?status=ungueltig')
                ->assertRedirect($url)
                ->assertSessionHasErrors('status');
        }
    }

    public function test_register_access_rejects_customer_users_and_nested_route_substitution(): void
    {
        [$customer, $project, $finding, $actor] = $this->context();
        $otherCustomer = $this->customer();
        $customerUser = User::factory()->for($customer)->create(['role' => UserRole::Admin]);

        foreach (['evidence', 'findings', 'measures'] as $register) {
            $url = $this->url($customer, $project, $register);
            $this->actingAs($customerUser)->get($url)->assertForbidden();
            $this->actingAs($actor)->get($this->url($otherCustomer, $project, $register))->assertNotFound();
        }
    }

    public function test_historical_or_inactive_customer_registers_are_read_only(): void
    {
        foreach ([ProjectStatus::Completed, ProjectStatus::Archived] as $status) {
            [$customer, $project, $finding, $actor] = $this->context(['status' => $status]);
            $this->assertReadOnlyRegisters($customer, $project, $actor);
        }

        [$customer, $project, $finding, $actor] = $this->context();
        $customer->update(['is_active' => false]);
        $this->assertReadOnlyRegisters($customer, $project, $actor);
    }

    /** @return array{Organization, IsmsProject, Finding, User} */
    private function context(array $projectAttributes = []): array
    {
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create($projectAttributes);
        $actor = User::factory()
            ->for(Organization::factory()->create(['organization_type' => 'internal']))
            ->create(['role' => UserRole::Consultant]);
        $finding = Finding::factory()->for($project)->create([
            'title' => 'Zugriffsprüfung fehlt',
            'severity' => FindingSeverity::High,
            'status' => FindingStatus::Accepted,
            'proposed_by' => $actor->id,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);

        return [$customer, $project, $finding, $actor];
    }

    private function customer(): Organization
    {
        return Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);
    }

    private function url(Organization $organization, IsmsProject $project, string $register): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/'.$register;
    }

    private function assertReadOnlyRegisters(Organization $customer, IsmsProject $project, User $actor): void
    {
        foreach (['evidence', 'findings', 'measures'] as $register) {
            $this->actingAs($actor)->get($this->url($customer, $project, $register))
                ->assertOk()
                ->assertInertia(fn (Assert $page): Assert => $page->where('canManage', false));
        }
    }
}
