<?php

namespace Tests\Feature\Evidence;

use App\Enums\UserRole;
use App\Models\AssessmentQuestion;
use App\Models\AuditEvent;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProjectAssessment;
use App\Models\User;
use App\Services\Assessment\AnswerValidator;
use App\Services\Assessment\AnswerWriter;
use App\Services\Assessment\AssessmentStarter;
use App\Services\Audit\AuditLogger;
use App\Services\Evidence\EvidenceLinkService;
use App\Services\Evidence\EvidenceUploadService;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EvidenceUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AssessmentCatalogSeeder::class);
        Storage::fake('evidence');
    }

    public function test_new_upload_persists_opaque_object_metadata_question_link_and_audit_event(): void
    {
        [$project, $assessment, $question, $actor] = $this->context();

        $evidence = app(EvidenceUploadService::class)->uploadForQuestion(
            $project,
            $question,
            UploadedFile::fake()->createWithContent('policy.txt', 'approved policy'),
            $actor,
        );

        $this->assertMatchesRegularExpression(
            '#^projects/'.preg_quote($project->id, '#').'/[0-9a-f-]{36}\\.txt$#',
            $evidence->storage_path,
        );
        Storage::disk('evidence')->assertExists($evidence->storage_path);
        $this->assertSame('policy.txt', $evidence->original_name);
        $this->assertSame('text/plain', $evidence->mime_type);
        $this->assertSame('txt', $evidence->file_kind);
        $this->assertSame(strlen('approved policy'), $evidence->size_bytes);
        $this->assertSame(hash('sha256', 'approved policy'), $evidence->sha256);
        $this->assertSame($actor->id, $evidence->uploaded_by);
        $this->assertDatabaseCount('evidence_files', 1);
        $this->assertDatabaseHas('evidence_question_links', [
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'evidence_file_id' => $evidence->id,
        ]);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'evidence.uploaded')->count());
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'evidence.linked')->count());
    }

    public function test_duplicate_bytes_reuse_one_object_and_add_one_link_with_linked_audit_event(): void
    {
        [$project, $assessment, $question, $actor] = $this->context();
        $otherQuestion = $assessment->questions()
            ->where('question_key', 'governance.objectives')
            ->sole();

        $first = app(EvidenceUploadService::class)->uploadForQuestion(
            $project,
            $question,
            UploadedFile::fake()->createWithContent('policy.txt', 'approved policy'),
            $actor,
        );
        $second = app(EvidenceUploadService::class)->uploadForQuestion(
            $project,
            $otherQuestion,
            UploadedFile::fake()->createWithContent('renamed-policy.txt', 'approved policy'),
            $actor,
        );

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('evidence_files', 1);
        $this->assertCount(1, Storage::disk('evidence')->allFiles());
        $this->assertDatabaseCount('evidence_question_links', 2);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'evidence.uploaded')->count());
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'evidence.linked')->count());
    }

    public function test_hidden_or_foreign_question_is_rejected_before_storage_or_database_writes(): void
    {
        [$project, $assessment, $question, $actor] = $this->context();
        $trigger = $assessment->questions()->where('question_key', 'backup.available')->sole();
        $hiddenQuestion = $assessment->questions()->where('question_key', 'backup.frequency')->sole();
        app(AnswerWriter::class)->save(
            $assessment,
            $trigger,
            app(AnswerValidator::class)->validate($trigger, [
                'answer' => false,
                'compliance_status' => 'not_fulfilled',
            ]),
            $actor,
        );
        $foreignAssessment = app(AssessmentStarter::class)->start(IsmsProject::factory()->create(), $actor);
        $foreignQuestion = $foreignAssessment->questions()->firstOrFail();

        foreach ([$hiddenQuestion, $foreignQuestion] as $invalidQuestion) {
            try {
                app(EvidenceUploadService::class)->uploadForQuestion(
                    $project,
                    $invalidQuestion,
                    UploadedFile::fake()->createWithContent('policy.txt', 'approved policy'),
                    $actor,
                );
                $this->fail('Expected question validation failure.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('evidence_files', 0);
                $this->assertDatabaseCount('evidence_question_links', 0);
                $this->assertCount(0, Storage::disk('evidence')->allFiles());
                $this->assertDatabaseCount('audit_events', 0);
            }
        }
    }

    public function test_storage_failure_creates_no_metadata_link_or_audit_event(): void
    {
        [$project, , $question, $actor] = $this->context();
        Storage::shouldReceive('disk')->with('evidence')->andThrow(new \RuntimeException('Simulated storage failure.'));

        $this->expectException(\RuntimeException::class);

        try {
            app(EvidenceUploadService::class)->uploadForQuestion(
                $project,
                $question,
                UploadedFile::fake()->createWithContent('policy.txt', 'approved policy'),
                $actor,
            );
        } finally {
            $this->assertDatabaseCount('evidence_files', 0);
            $this->assertDatabaseCount('evidence_question_links', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public function test_audit_failure_rolls_back_metadata_and_removes_only_new_object(): void
    {
        [$project, , $question, $actor] = $this->context();
        $this->app->instance(AuditLogger::class, $this->failingAuditLogger());

        try {
            app(EvidenceUploadService::class)->uploadForQuestion(
                $project,
                $question,
                UploadedFile::fake()->createWithContent('policy.txt', 'approved policy'),
                $actor,
            );
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('evidence_files', 0);
            $this->assertDatabaseCount('evidence_question_links', 0);
            $this->assertDatabaseCount('audit_events', 0);
            $this->assertCount(0, Storage::disk('evidence')->allFiles());
        }
    }

    public function test_finding_link_is_project_scoped_and_idempotent(): void
    {
        [$project, $assessment, $question, $actor] = $this->context();
        $evidence = EvidenceFile::factory()->for($project)->create(['uploaded_by' => $actor->id]);
        $finding = Finding::factory()->for($project)->create([
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'proposed_by' => $actor->id,
        ]);

        app(EvidenceLinkService::class)->linkToFinding($project, $evidence, $finding, $actor);
        app(EvidenceLinkService::class)->linkToFinding($project, $evidence, $finding, $actor);

        $this->assertDatabaseCount('evidence_finding_links', 1);
        $this->assertDatabaseHas('evidence_finding_links', [
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
            'evidence_file_id' => $evidence->id,
            'finding_id' => $finding->id,
        ]);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'evidence.linked')->count());
    }

    /** @return array{IsmsProject, ProjectAssessment, AssessmentQuestion, User} */
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
        $assessment = app(AssessmentStarter::class)->start($project, $actor);

        return [$project, $assessment, $assessment->questions()->firstOrFail(), $actor];
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
