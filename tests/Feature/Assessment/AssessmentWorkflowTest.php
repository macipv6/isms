<?php

namespace Tests\Feature\Assessment;

use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssessmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_can_be_started_resumed_and_answered(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $actor = $this->consultant();
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create(['name' => 'ISMS 2026']);
        $url = '/organizations/'.$customer->id.'/projects/'.$project->id.'/assessment';

        $this->actingAs($actor)->post($url)->assertRedirect($url);
        $this->actingAs($actor)->post($url)->assertRedirect($url);
        $this->assertDatabaseCount('project_assessments', 1);

        $assessment = $project->assessment()->sole();
        $question = $assessment->questions()->where('question_key', 'governance.policy_exists')->sole();

        $this->actingAs($actor)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('assessments/Show')
                ->where('organization.id', $customer->id)
                ->where('project.id', $project->id)
                ->where('project.name', 'ISMS 2026')
                ->where('catalogVersion', '2026.1')
                ->where('progress.answered', 0)
                ->where('progress.total', 16)
                ->has('categories', 11)
                ->has('categories.0.questions.0.answer_type')
                ->has('categories.0.questions.0.compliance_status'));

        $this->actingAs($actor)
            ->put($url.'/questions/'.$question->id, [
                'answer' => true,
                'compliance_status' => 'fulfilled',
                'comment' => 'Leitlinie geprüft',
            ])
            ->assertRedirect($url)
            ->assertSessionHas('success');

        $this->actingAs($actor)
            ->get($url)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('flash.success', 'Antwort gespeichert.'));

        $this->assertDatabaseHas('project_answers', [
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'answer_value' => 'true',
            'compliance_status' => 'fulfilled',
            'answered_by' => $actor->id,
        ]);
    }

    private function consultant(): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
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
