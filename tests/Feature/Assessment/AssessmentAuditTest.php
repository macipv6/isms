<?php

namespace Tests\Feature\Assessment;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\AssessmentStarter;
use App\Services\Audit\AuditLogger;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_and_answer_are_audited_without_answer_or_comment_content(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $actor = User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);
        $project = IsmsProject::factory()->for($customer)->create();
        $url = '/organizations/'.$customer->id.'/projects/'.$project->id.'/assessment';

        $this->actingAs($actor)->post($url);
        $this->actingAs($actor)->post($url);
        $assessment = $project->assessment()->sole();
        $question = $assessment->questions()->where('question_key', 'governance.objectives')->sole();
        $this->actingAs($actor)->put($url.'/questions/'.$question->id, [
            'answer' => 'Streng vertrauliches Sicherheitsziel',
            'compliance_status' => 'partial',
            'comment' => 'Geheimer interner Kommentar',
        ]);

        $this->assertSame(1, AuditEvent::query()->where('event_type', 'assessment.started')->count());
        $answerEvent = AuditEvent::query()->where('event_type', 'assessment.answer_saved')->sole();
        $context = json_encode($answerEvent->context, JSON_THROW_ON_ERROR);

        $this->assertSame($project->id, $answerEvent->context['project_id']);
        $this->assertSame('governance.objectives', $answerEvent->context['question_key']);
        $this->assertStringNotContainsString('Streng vertraulich', $context);
        $this->assertStringNotContainsString('Geheimer interner Kommentar', $context);
    }

    public function test_failed_start_audit_rolls_back_assessment_snapshot(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        [$actor, $customer, $project, $url] = $this->context();
        $this->app->instance(AuditLogger::class, $this->failingAuditLogger());

        try {
            $this->actingAs($actor)->post($url);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('project_assessments', 0);
            $this->assertDatabaseCount('assessment_questions', 0);
        }
    }

    public function test_failed_answer_audit_rolls_back_answer_write(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        [$actor, $customer, $project, $url] = $this->context();
        $assessment = app(AssessmentStarter::class)->start($project, $actor);
        $question = $assessment->questions()->where('question_key', 'governance.policy_exists')->sole();
        $this->app->instance(AuditLogger::class, $this->failingAuditLogger());

        try {
            $this->actingAs($actor)->put($url.'/questions/'.$question->id, [
                'answer' => true,
                'compliance_status' => 'fulfilled',
            ]);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('project_answers', 0);
        }
    }

    /** @return array{User, Organization, IsmsProject, string} */
    private function context(): array
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $actor = User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);
        $project = IsmsProject::factory()->for($customer)->create();

        return [
            $actor,
            $customer,
            $project,
            '/organizations/'.$customer->id.'/projects/'.$project->id.'/assessment',
        ];
    }

    private function failingAuditLogger(): AuditLogger
    {
        return new class extends AuditLogger
        {
            public function record(
                string $eventType,
                ?User $actor,
                array $context = [],
                ?string $organizationId = null,
            ): AuditEvent {
                throw new \RuntimeException('Simulated audit failure.');
            }
        };
    }
}
