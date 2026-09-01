<?php

namespace Tests\Feature\Assessment;

use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\AnswerValidator;
use App\Services\Assessment\AnswerWriter;
use App\Services\Assessment\AssessmentStarter;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AnswerPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_answer_is_created_and_updated_once_with_server_actor_metadata(): void
    {
        [$assessment, $actor] = $this->assessment();
        $question = $assessment->questions()->where('question_key', 'governance.policy_exists')->sole();
        $validator = app(AnswerValidator::class);
        $writer = app(AnswerWriter::class);

        $first = $writer->save($assessment, $question, $validator->validate($question, [
            'answer' => true,
            'compliance_status' => 'fulfilled',
            'comment' => 'Leitlinie geprüft',
        ]), $actor);
        $second = $writer->save($assessment, $question, $validator->validate($question, [
            'answer' => false,
            'compliance_status' => 'partial',
            'comment' => 'Überarbeitung erforderlich',
        ]), $actor);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('project_answers', 1);
        $this->assertSame('false', $second->fresh()->answer_value);
        $this->assertSame($actor->id, $second->answered_by);
        $this->assertNotNull($second->answered_at);
    }

    public function test_hidden_answer_is_preserved_but_cannot_be_written_while_hidden(): void
    {
        [$assessment, $actor] = $this->assessment();
        $trigger = $assessment->questions()->where('question_key', 'backup.available')->sole();
        $followUp = $assessment->questions()->where('question_key', 'backup.frequency')->sole();
        $validator = app(AnswerValidator::class);
        $writer = app(AnswerWriter::class);

        $writer->save($assessment, $trigger, $validator->validate($trigger, [
            'answer' => true,
            'compliance_status' => 'fulfilled',
        ]), $actor);
        $savedFollowUp = $writer->save($assessment, $followUp, $validator->validate($followUp, [
            'answer' => 'daily',
            'compliance_status' => 'fulfilled',
        ]), $actor);
        $writer->save($assessment, $trigger, $validator->validate($trigger, [
            'answer' => false,
            'compliance_status' => 'not_fulfilled',
        ]), $actor);

        $this->assertSame('daily', $savedFollowUp->fresh()->answer_value);
        $this->assertDatabaseCount('project_answers', 2);

        $this->expectException(ValidationException::class);
        $writer->save($assessment, $followUp, $validator->validate($followUp, [
            'answer' => 'weekly',
            'compliance_status' => 'partial',
        ]), $actor);
    }

    /**
     * @return array{0: \App\Models\ProjectAssessment, 1: User}
     */
    private function assessment(): array
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $actor = User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
        $assessment = app(AssessmentStarter::class)->start(IsmsProject::factory()->create(), $actor);

        return [$assessment, $actor];
    }
}
