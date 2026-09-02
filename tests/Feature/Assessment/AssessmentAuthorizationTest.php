<?php

namespace Tests\Feature\Assessment;

use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\AssessmentStarter;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_start_or_view_assessment(): void
    {
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create();

        $this->post($this->assessmentUrl($customer, $project))->assertRedirect('/login');
        $this->get($this->assessmentUrl($customer, $project))->assertRedirect('/login');
        $this->assertDatabaseCount('project_assessments', 0);
    }

    public function test_admin_and_consultant_can_start_customer_assessment(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);

        foreach ([UserRole::Admin, UserRole::Consultant] as $role) {
            $customer = $this->customer();
            $project = IsmsProject::factory()->for($customer)->create();

            $this->actingAs($this->user($role))
                ->post($this->assessmentUrl($customer, $project))
                ->assertRedirect($this->assessmentUrl($customer, $project));
        }

        $this->assertDatabaseCount('project_assessments', 2);
    }

    public function test_cross_organization_and_cross_project_route_tampering_is_rejected_without_writes(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $actor = $this->user(UserRole::Consultant);
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create();
        $otherProject = IsmsProject::factory()->for($otherCustomer)->create();
        $assessment = app(AssessmentStarter::class)->start($project, $actor);
        $question = $assessment->questions()->where('question_key', 'governance.policy_exists')->sole();

        $this->actingAs($actor)
            ->post($this->assessmentUrl($otherCustomer, $project))
            ->assertNotFound();
        $this->actingAs($actor)
            ->get($this->assessmentUrl($otherCustomer, $project))
            ->assertNotFound();
        $this->actingAs($actor)
            ->put($this->assessmentUrl($otherCustomer, $otherProject).'/questions/'.$question->id, [
                'answer' => true,
                'compliance_status' => 'fulfilled',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('project_answers', 0);
    }

    public function test_inactive_customer_assessment_is_readable_but_cannot_be_started_or_changed(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $actor = $this->user(UserRole::Consultant);
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create();
        $assessment = app(AssessmentStarter::class)->start($project, $actor);
        $question = $assessment->questions()->where('question_key', 'governance.policy_exists')->sole();
        $customer->update(['is_active' => false]);

        $this->actingAs($actor)
            ->get($this->assessmentUrl($customer, $project))
            ->assertOk();
        $this->actingAs($actor)
            ->put($this->assessmentUrl($customer, $project).'/questions/'.$question->id, [
                'answer' => true,
                'compliance_status' => 'fulfilled',
            ])
            ->assertForbidden();

        $newCustomer = $this->customer(['is_active' => false]);
        $newProject = IsmsProject::factory()->for($newCustomer)->create();
        $this->actingAs($actor)
            ->post($this->assessmentUrl($newCustomer, $newProject))
            ->assertForbidden();
        $this->assertDatabaseCount('project_answers', 0);
    }

    public function test_customer_organization_user_cannot_access_any_customer_assessment(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $internalActor = $this->user(UserRole::Consultant);
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create();
        $otherProject = IsmsProject::factory()->for($otherCustomer)->create();
        $assessment = app(AssessmentStarter::class)->start($project, $internalActor);
        $question = $assessment->questions()->where('question_key', 'governance.policy_exists')->sole();
        $customerUser = User::factory()->for($customer)->create(['role' => UserRole::Admin]);

        $this->actingAs($customerUser)
            ->get($this->assessmentUrl($customer, $project))
            ->assertForbidden();
        $this->actingAs($customerUser)
            ->post($this->assessmentUrl($otherCustomer, $otherProject))
            ->assertForbidden();
        $this->actingAs($customerUser)
            ->put($this->assessmentUrl($customer, $project).'/questions/'.$question->id, [
                'answer' => true,
                'compliance_status' => 'fulfilled',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('project_answers', 0);
        $this->assertDatabaseCount('project_assessments', 1);
    }

    private function assessmentUrl(Organization $organization, IsmsProject $project): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/assessment';
    }

    private function user(UserRole $role): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => $role]);
    }

    /** @param array<string, mixed> $attributes */
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
