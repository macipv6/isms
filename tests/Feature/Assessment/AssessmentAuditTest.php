<?php

namespace Tests\Feature\Assessment;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
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
}
