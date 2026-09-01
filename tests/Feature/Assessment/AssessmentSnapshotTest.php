<?php

namespace Tests\Feature\Assessment;

use App\Enums\UserRole;
use App\Models\CatalogQuestion;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\AssessmentStarter;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_assessment_copies_the_published_catalog_into_project_snapshot(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $actor = $this->actor();
        $project = IsmsProject::factory()->create();

        $assessment = app(AssessmentStarter::class)->start($project, $actor);
        $source = CatalogQuestion::query()
            ->where('question_key', 'backup.frequency')
            ->with(['options', 'incomingRules.triggerQuestion'])
            ->sole();
        $snapshot = $assessment->questions()
            ->where('question_key', 'backup.frequency')
            ->sole();

        $this->assertSame($project->id, $assessment->project_id);
        $this->assertSame($actor->id, $assessment->started_by);
        $this->assertSame('BSI', $assessment->framework_key);
        $this->assertSame('2026.1', $assessment->catalog_version);
        $this->assertSame('2026.1', $assessment->catalogVersion->version);
        $this->assertSame(CatalogQuestion::query()->where('is_active', true)->count(), $assessment->questions()->count());
        $this->assertSame($source->id, $snapshot->source_question_id);
        $this->assertSame('backup', $snapshot->category_key);
        $this->assertSame('Datensicherung und Wiederherstellung', $snapshot->category_name);
        $this->assertSame($source->question_text, $snapshot->question_text);
        $this->assertSame('single_choice', $snapshot->answer_type->value);
        $this->assertCount(4, $snapshot->options);
        $this->assertSame('daily', $snapshot->options[1]['value']);
        $this->assertSame('backup.available', $snapshot->rules[0]['trigger_question_key']);
        $this->assertSame(true, $snapshot->rules[0]['expected_value']);
    }

    public function test_starting_same_project_twice_returns_the_existing_snapshot(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $actor = $this->actor();
        $project = IsmsProject::factory()->create();
        $starter = app(AssessmentStarter::class);

        $first = $starter->start($project, $actor);
        $second = $starter->start($project, $actor);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('project_assessments', 1);
        $this->assertDatabaseCount('assessment_questions', 21);
    }

    public function test_catalog_changes_do_not_change_existing_project_snapshot(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $project = IsmsProject::factory()->create();
        $assessment = app(AssessmentStarter::class)->start($project, $this->actor());
        $source = CatalogQuestion::query()
            ->where('question_key', 'backup.frequency')
            ->sole();
        $snapshotBefore = $assessment->questions()
            ->where('question_key', 'backup.frequency')
            ->sole();

        $source->update(['question_text' => 'Nachträglich geänderter Katalogtext']);
        $source->options()->where('value', 'daily')->update(['label' => 'Geänderte Option']);

        $snapshotAfter = $snapshotBefore->fresh();
        $this->assertNotNull($snapshotAfter);
        $this->assertNotSame('Nachträglich geänderter Katalogtext', $snapshotAfter->question_text);
        $this->assertSame('Täglich', $snapshotAfter->options[1]['label']);
    }

    public function test_catalog_version_metadata_is_frozen_for_existing_assessment(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $assessment = app(AssessmentStarter::class)->start(
            IsmsProject::factory()->create(),
            $this->actor(),
        );

        $assessment->catalogVersion->update(['version' => 'central-version-changed']);

        $this->assertSame('BSI', $assessment->fresh()?->framework_key);
        $this->assertSame('2026.1', $assessment->fresh()?->catalog_version);
    }

    private function actor(): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
    }
}
