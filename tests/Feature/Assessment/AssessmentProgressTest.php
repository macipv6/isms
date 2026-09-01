<?php

namespace Tests\Feature\Assessment;

use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\AnswerValidator;
use App\Services\Assessment\AnswerWriter;
use App\Services\Assessment\AssessmentProgress;
use App\Services\Assessment\AssessmentStarter;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_tracks_only_currently_applicable_questions(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $actor = User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
        $assessment = app(AssessmentStarter::class)->start(IsmsProject::factory()->create(), $actor);
        $progress = app(AssessmentProgress::class);
        $writer = app(AnswerWriter::class);
        $validator = app(AnswerValidator::class);
        $trigger = $assessment->questions()->where('question_key', 'backup.available')->sole();
        $followUp = $assessment->questions()->where('question_key', 'backup.frequency')->sole();

        $this->assertSame([
            'answered' => 0,
            'total' => 16,
            'percentage' => 0,
        ], array_intersect_key($progress->for($assessment), array_flip(['answered', 'total', 'percentage'])));

        $writer->save($assessment, $trigger, $validator->validate($trigger, [
            'answer' => true,
            'compliance_status' => 'fulfilled',
        ]), $actor);
        $revealed = $progress->for($assessment);
        $this->assertSame(1, $revealed['answered']);
        $this->assertSame(20, $revealed['total']);
        $this->assertSame(5, $revealed['percentage']);

        $writer->save($assessment, $followUp, $validator->validate($followUp, [
            'answer' => null,
            'compliance_status' => 'not_applicable',
        ]), $actor);
        $this->assertSame(2, $progress->for($assessment)['answered']);

        $writer->save($assessment, $trigger, $validator->validate($trigger, [
            'answer' => false,
            'compliance_status' => 'not_fulfilled',
        ]), $actor);
        $hidden = $progress->for($assessment);
        $this->assertSame(1, $hidden['answered']);
        $this->assertSame(16, $hidden['total']);
        $this->assertSame(6, $hidden['percentage']);
        $this->assertCount(11, $hidden['categories']);
    }
}
