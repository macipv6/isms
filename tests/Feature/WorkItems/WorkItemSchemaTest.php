<?php

namespace Tests\Feature\WorkItems;

use App\Enums\EvidenceReviewStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\MeasurePriority;
use App\Enums\MeasureStatus;
use App\Models\AssessmentQuestion;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\ProjectAssessment;
use App\Models\User;
use App\Services\Assessment\AssessmentStarter;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkItemSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AssessmentCatalogSeeder::class);
    }

    public function test_work_item_tables_contain_the_required_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('evidence_files', [
            'id',
            'project_id',
            'storage_path',
            'original_name',
            'mime_type',
            'file_kind',
            'size_bytes',
            'sha256',
            'status',
            'uploaded_by',
            'uploaded_at',
            'reviewed_by',
            'reviewed_at',
            'review_note',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('findings', [
            'id',
            'project_id',
            'project_assessment_id',
            'assessment_question_id',
            'title',
            'description',
            'severity',
            'status',
            'proposed_by',
            'proposed_at',
            'decided_by',
            'decided_at',
            'decision_note',
            'closed_by',
            'closed_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('measures', [
            'id',
            'project_id',
            'finding_id',
            'title',
            'description',
            'priority',
            'responsible_name',
            'responsible_email',
            'due_date',
            'status',
            'created_by',
            'completed_by',
            'completed_at',
            'cancelled_reason',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('evidence_question_links', [
            'id',
            'project_id',
            'project_assessment_id',
            'assessment_question_id',
            'evidence_file_id',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('evidence_finding_links', [
            'id',
            'project_id',
            'project_assessment_id',
            'finding_id',
            'evidence_file_id',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_factories_persist_enum_backed_work_item_attributes(): void
    {
        $evidence = EvidenceFile::factory()->create([
            'status' => EvidenceReviewStatus::Verified,
        ]);
        $finding = Finding::factory()->create([
            'severity' => FindingSeverity::Critical,
            'status' => FindingStatus::Accepted,
        ]);
        $measure = Measure::factory()->create([
            'priority' => MeasurePriority::High,
            'status' => MeasureStatus::InProgress,
        ]);

        $this->assertSame(EvidenceReviewStatus::Verified, $evidence->fresh()?->status);
        $this->assertSame(FindingSeverity::Critical, $finding->fresh()?->severity);
        $this->assertSame(FindingStatus::Accepted, $finding->fresh()?->status);
        $this->assertSame(MeasurePriority::High, $measure->fresh()?->priority);
        $this->assertSame(MeasureStatus::InProgress, $measure->fresh()?->status);
    }

    public function test_evidence_digest_is_unique_only_inside_one_project(): void
    {
        $project = IsmsProject::factory()->create();
        EvidenceFile::factory()->for($project)->create(['sha256' => str_repeat('a', 64)]);
        EvidenceFile::factory()->for(IsmsProject::factory()->create())->create([
            'sha256' => str_repeat('a', 64),
        ]);

        $this->expectException(QueryException::class);
        EvidenceFile::factory()->for($project)->create(['sha256' => str_repeat('a', 64)]);
    }

    #[DataProvider('immutableEvidenceMetadataFields')]
    public function test_each_evidence_original_metadata_field_cannot_be_changed(
        string $field,
        mixed $replacement,
    ): void {
        $evidence = EvidenceFile::factory()->create();

        if ($field === 'uploaded_by') {
            $replacement = User::factory()->create()->id;
        }
        if ($field === 'size_bytes') {
            $replacement = $evidence->size_bytes + 1;
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('evidence original metadata is immutable');
        DB::table('evidence_files')->where('id', $evidence->id)->update([$field => $replacement]);
    }

    public function test_evidence_review_metadata_remains_mutable(): void
    {
        $evidence = EvidenceFile::factory()->create();
        $reviewer = User::factory()->create();
        $reviewedAt = now()->addMinute();

        DB::table('evidence_files')->where('id', $evidence->id)->update([
            'status' => EvidenceReviewStatus::Verified->value,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => $reviewedAt,
        ]);

        $updated = $evidence->fresh();

        $this->assertNotNull($updated);
        $this->assertSame(EvidenceReviewStatus::Verified, $updated->status);
        $this->assertSame($reviewer->id, $updated->reviewed_by);
        $this->assertTrue($reviewedAt->equalTo($updated->reviewed_at));
    }

    public function test_finding_cannot_reference_an_assessment_from_another_project(): void
    {
        $project = IsmsProject::factory()->create();
        [$assessment, $question] = $this->assessmentQuestion($project);

        $this->expectException(QueryException::class);
        Finding::factory()->for(IsmsProject::factory()->create())->create([
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
        ]);
    }

    public function test_measure_cannot_reference_a_finding_from_another_project(): void
    {
        $project = IsmsProject::factory()->create();
        [$assessment, $question] = $this->assessmentQuestion($project);
        $finding = $this->findingFor($project, $assessment, $question);

        $this->expectException(QueryException::class);
        Measure::factory()->for(IsmsProject::factory()->create())->create([
            'finding_id' => $finding->id,
        ]);
    }

    public function test_question_evidence_link_cannot_cross_projects(): void
    {
        $project = IsmsProject::factory()->create();
        [$assessment, $question] = $this->assessmentQuestion($project);
        $evidence = EvidenceFile::factory()->for(IsmsProject::factory()->create())->create();

        $this->expectException(QueryException::class);
        DB::table('evidence_question_links')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'evidence_file_id' => $evidence->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_finding_evidence_link_cannot_cross_projects(): void
    {
        $project = IsmsProject::factory()->create();
        [$assessment, $question] = $this->assessmentQuestion($project);
        $finding = $this->findingFor($project, $assessment, $question);
        $evidence = EvidenceFile::factory()->for(IsmsProject::factory()->create())->create();

        $this->expectException(QueryException::class);
        DB::table('evidence_finding_links')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
            'finding_id' => $finding->id,
            'evidence_file_id' => $evidence->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_duplicate_evidence_links_are_rejected(): void
    {
        $project = IsmsProject::factory()->create();
        [$assessment, $question] = $this->assessmentQuestion($project);
        $evidence = EvidenceFile::factory()->for($project)->create();
        $link = [
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'evidence_file_id' => $evidence->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('evidence_question_links')->insert(['id' => (string) Str::uuid(), ...$link]);

        $this->expectException(QueryException::class);
        DB::table('evidence_question_links')->insert(['id' => (string) Str::uuid(), ...$link]);
    }

    public function test_duplicate_evidence_finding_links_are_rejected(): void
    {
        $project = IsmsProject::factory()->create();
        [$assessment, $question] = $this->assessmentQuestion($project);
        $finding = $this->findingFor($project, $assessment, $question);
        $evidence = EvidenceFile::factory()->for($project)->create();
        $link = [
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
            'finding_id' => $finding->id,
            'evidence_file_id' => $evidence->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('evidence_finding_links')->insert(['id' => (string) Str::uuid(), ...$link]);

        $this->expectException(QueryException::class);
        DB::table('evidence_finding_links')->insert(['id' => (string) Str::uuid(), ...$link]);
    }

    public function test_evidence_question_relationship_can_attach_and_sync_without_pivot_id(): void
    {
        $project = IsmsProject::factory()->create();
        [$assessment, $question] = $this->assessmentQuestion($project);
        $replacementQuestion = $assessment->questions()->whereKeyNot($question->id)->firstOrFail();
        $evidence = EvidenceFile::factory()->for($project)->create();
        $pivot = [
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
        ];

        $evidence->questions()->attach($question, $pivot);
        $this->assertNotNull(DB::table('evidence_question_links')->value('id'));

        $evidence->questions()->sync([$replacementQuestion->id => $pivot]);

        $this->assertDatabaseMissing('evidence_question_links', [
            'evidence_file_id' => $evidence->id,
            'assessment_question_id' => $question->id,
        ]);
        $this->assertDatabaseHas('evidence_question_links', [
            'evidence_file_id' => $evidence->id,
            'assessment_question_id' => $replacementQuestion->id,
        ]);
    }

    public function test_evidence_finding_relationship_can_attach_and_sync_without_pivot_id(): void
    {
        $project = IsmsProject::factory()->create();
        [$assessment, $question] = $this->assessmentQuestion($project);
        $replacementQuestion = $assessment->questions()->whereKeyNot($question->id)->firstOrFail();
        $finding = $this->findingFor($project, $assessment, $question);
        $replacementFinding = $this->findingFor($project, $assessment, $replacementQuestion);
        $evidence = EvidenceFile::factory()->for($project)->create();
        $pivot = [
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
        ];

        $evidence->findings()->attach($finding, $pivot);
        $this->assertNotNull(DB::table('evidence_finding_links')->value('id'));

        $evidence->findings()->sync([$replacementFinding->id => $pivot]);

        $this->assertDatabaseMissing('evidence_finding_links', [
            'evidence_file_id' => $evidence->id,
            'finding_id' => $finding->id,
        ]);
        $this->assertDatabaseHas('evidence_finding_links', [
            'evidence_file_id' => $evidence->id,
            'finding_id' => $replacementFinding->id,
        ]);
    }

    public function test_only_one_proposed_or_accepted_finding_can_exist_for_a_question(): void
    {
        $project = IsmsProject::factory()->create();
        [$assessment, $question] = $this->assessmentQuestion($project);
        $attributes = [
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
        ];

        $this->findingFor($project, $assessment, $question, FindingStatus::Rejected);
        Finding::factory()->for($project)->create([...$attributes, 'status' => FindingStatus::Proposed]);

        $this->expectException(QueryException::class);
        Finding::factory()->for($project)->create([...$attributes, 'status' => FindingStatus::Accepted]);
    }

    /**
     * @return array{0: ProjectAssessment, 1: AssessmentQuestion}
     */
    private function assessmentQuestion(IsmsProject $project): array
    {
        $assessment = app(AssessmentStarter::class)->start($project, User::factory()->create());

        return [$assessment, $assessment->questions()->firstOrFail()];
    }

    private function findingFor(
        IsmsProject $project,
        ProjectAssessment $assessment,
        AssessmentQuestion $question,
        FindingStatus $status = FindingStatus::Proposed,
    ): Finding {
        return Finding::factory()->for($project)->create([
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function immutableEvidenceMetadataFields(): array
    {
        return [
            'storage path' => ['storage_path', 'evidence/replacement.pdf'],
            'original name' => ['original_name', 'replacement.pdf'],
            'mime type' => ['mime_type', 'text/plain'],
            'file kind' => ['file_kind', 'txt'],
            'size bytes' => ['size_bytes', 4096],
            'sha256' => ['sha256', str_repeat('b', 64)],
            'uploaded by' => ['uploaded_by', null],
            'uploaded at' => ['uploaded_at', '2030-01-01 00:00:00+00'],
        ];
    }
}
