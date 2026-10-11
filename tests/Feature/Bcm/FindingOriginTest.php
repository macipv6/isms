<?php

namespace Tests\Feature\Bcm;

use App\Data\Dependencies\DependencyNode;
use App\Enums\FindingOrigin;
use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Models\BcmExercise;
use App\Models\BcmExerciseCriterion;
use App\Models\BcmExerciseEvidenceLink;
use App\Models\BcmExerciseResult;
use App\Models\BusinessProcess;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Evidence\EvidenceLinkService;
use App\Services\Findings\FindingWorkflow;
use App\Services\Measures\MeasureWorkflow;
use App\Services\Risks\FindingRiskTargetService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FindingOriginTest extends TestCase
{
    use InteractsWithBcm;
    use RefreshDatabase;

    public function test_schema_supports_both_origins_and_existing_assessment_findings(): void
    {
        $this->assertTrue(Schema::hasTable('bcm_exercise_results'));
        $finding = Finding::factory()->create();
        $this->assertSame(FindingOrigin::AssessmentQuestion, $finding->fresh()->origin_type);
        $this->assertNotNull($finding->question);
        $this->assertNull($finding->bcm_exercise_result_id);
    }

    public function test_sql_rejects_ambiguous_missing_and_foreign_project_origins(): void
    {
        [$project, $actor] = $this->bcmProject();
        $result = $this->exerciseResult($project, $actor);
        $assessment = Finding::factory()->for($project)->create(['status' => 'rejected']);
        $base = $this->findingData($project, $result, $actor);
        $this->rejectSql(fn () => DB::table('findings')->insert([...$base, 'origin_type' => 'assessment_question', 'project_assessment_id' => $assessment->project_assessment_id, 'assessment_question_id' => $assessment->assessment_question_id]));
        $this->rejectSql(fn () => DB::table('findings')->insert([...$base, 'bcm_exercise_result_id' => null]));
        $this->rejectSql(fn () => DB::table('findings')->insert([...$base, 'origin_type' => 'assessment_question', 'bcm_exercise_result_id' => null]));
        $this->rejectSql(fn () => DB::table('findings')->insert([...$base, 'project_assessment_id' => $assessment->project_assessment_id]));
        $this->rejectSql(fn () => DB::table('findings')->insert([...$base, 'origin_type' => 'assessment_question', 'bcm_exercise_result_id' => null, 'assessment_question_id' => $assessment->assessment_question_id]));
        $this->rejectSql(fn () => DB::table('findings')->insert([...$base, 'origin_type' => 'other']));
        $foreign = IsmsProject::factory()->create();
        $this->rejectSql(fn () => DB::table('findings')->insert([...$base, 'project_id' => $foreign->id]));
        $this->assertDatabaseCount('findings', 1);
    }

    public function test_one_active_finding_per_result_and_question_preserves_terminal_history(): void
    {
        [$project, $actor] = $this->bcmProject();
        $result = $this->exerciseResult($project, $actor);
        $finding = Finding::query()->create($this->findingData($project, $result, $actor));
        $this->rejectSql(fn () => DB::table('findings')->insert($this->findingData($project, $result, $actor)));
        $finding->update(['status' => FindingStatus::Rejected]);
        $replacement = Finding::query()->create($this->findingData($project, $result, $actor));
        $replacement->update(['status' => FindingStatus::Accepted]);
        $this->rejectSql(fn () => DB::table('findings')->insert($this->findingData($project, $result, $actor)));
        $assessment = Finding::factory()->create();
        $data = $assessment->getAttributes();
        $data['id'] = (string) Str::uuid();
        $this->rejectSql(fn () => DB::table('findings')->insert($data));
    }

    public function test_exercise_findings_support_common_workflow_measures_evidence_and_registers_without_assessment(): void
    {
        [$project, $actor] = $this->bcmProject();
        $result = $this->exerciseResult($project, $actor);
        $finding = Finding::query()->create($this->findingData($project, $result, $actor));
        $workflow = app(FindingWorkflow::class);
        $finding = $workflow->update($finding, ['title' => 'Reviewed gap', 'description' => 'Observed gap', 'severity' => 'high'], $actor);
        $finding = $workflow->decide($finding, FindingStatus::Accepted, null, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        app(FindingRiskTargetService::class)->link($finding, DependencyNode::process($process), $actor);
        $this->assertDatabaseHas('risk_analysis_triggers', ['project_id' => $project->id, 'source_finding_id' => $finding->id, 'status' => 'open']);
        $measure = app(MeasureWorkflow::class)->create($finding, ['title' => 'Recovery correction', 'description' => 'Correct gap', 'priority' => 'high', 'responsible_name' => 'Owner', 'due_date' => '2026-12-01'], $actor);
        $evidence = EvidenceFile::factory()->create(['project_id' => $project->id]);
        app(EvidenceLinkService::class)->linkToFinding($project, $evidence, $finding, $actor);
        $this->assertSame($evidence->id, $finding->evidenceFiles()->sole()->id);
        $this->assertNull($finding->assessment);
        $this->assertNull($finding->question);
        $this->assertSame($result->id, $finding->exerciseResult->id);
        $base = '/organizations/'.$project->organization_id.'/projects/'.$project->id;
        $this->actingAs($actor)->get($base.'/findings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('findings.0.origin_type', 'bcm_exercise_result')->where('findings.0.origin.id', $result->id)->where('findings.0.origin.label', 'BCM-Übung: Restore service')->where('findings.0.question_id', null));
        $this->get($base.'/measures')->assertOk()->assertInertia(fn (Assert $page) => $page->where('measures.0.finding.origin_type', 'bcm_exercise_result')->where('measures.0.finding.question_id', null));
        $this->get($base.'/evidence')->assertOk();
        $measure->update(['status' => MeasureStatus::Completed]);
        $this->assertSame(FindingStatus::Closed, $workflow->close($finding, $actor)->status);
        $this->assertDatabaseHas('risk_analysis_triggers', ['source_finding_id' => $finding->id, 'status' => 'superseded']);
    }

    public function test_evidence_links_cannot_bypass_project_or_assessment_provenance_with_null(): void
    {
        [$project, $actor] = $this->bcmProject();
        $finding = Finding::query()->create($this->findingData($project, $this->exerciseResult($project, $actor), $actor));
        $foreignEvidence = EvidenceFile::factory()->create();
        $link = ['id' => (string) Str::uuid(), 'project_id' => $foreignEvidence->project_id, 'project_assessment_id' => null, 'finding_id' => $finding->id, 'evidence_file_id' => $foreignEvidence->id];
        $this->rejectSql(fn () => DB::table('evidence_finding_links')->insert($link));
        $assessmentFinding = Finding::factory()->for($project)->create();
        $evidence = EvidenceFile::factory()->create(['project_id' => $project->id]);
        $this->rejectSql(fn () => DB::table('evidence_finding_links')->insert([...$link, 'project_id' => $project->id, 'finding_id' => $assessmentFinding->id, 'evidence_file_id' => $evidence->id]));
        $this->assertDatabaseCount('evidence_finding_links', 0);
    }

    public function test_criteria_results_and_evidence_are_project_bound_and_results_are_unique_per_criterion(): void
    {
        [$project, $actor] = $this->bcmProject();
        $result = $this->exerciseResult($project, $actor);
        $data = $result->getAttributes();
        $data['id'] = (string) Str::uuid();
        $this->rejectSql(fn () => DB::table('bcm_exercise_results')->insert($data));
        $foreign = IsmsProject::factory()->create();
        $this->rejectSql(fn () => DB::table('bcm_exercise_results')->where('id', $result->id)->update(['project_id' => $foreign->id]), '23503');
        $other = $this->exerciseResult($project, $actor);
        $this->rejectSql(fn () => DB::table('bcm_exercise_results')->where('id', $result->id)->update(['bcm_exercise_id' => $other->bcm_exercise_id]), '23503');
        $this->assertDatabaseHas('bcm_exercise_results', ['id' => $result->id, 'project_id' => $project->id, 'bcm_exercise_id' => $result->bcm_exercise_id, 'bcm_exercise_criterion_id' => $result->bcm_exercise_criterion_id]);
        $this->rejectSql(fn () => DB::table('bcm_exercise_criteria')->insert(['id' => (string) Str::uuid(), 'project_id' => $foreign->id, 'bcm_exercise_id' => $result->bcm_exercise_id, 'position' => 2]));
        $evidence = EvidenceFile::factory()->create(['project_id' => $foreign->id]);
        $this->rejectSql(fn () => DB::table('bcm_exercise_evidence_links')->insert(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'bcm_exercise_id' => $result->bcm_exercise_id, 'evidence_id' => $evidence->id]));
    }

    public function test_origin_migration_clean_rollback_and_upgrade_preserve_assessment_data(): void
    {
        $finding = Finding::factory()->create();
        $evidence = EvidenceFile::factory()->create(['project_id' => $finding->project_id]);
        DB::table('evidence_finding_links')->insert(['id' => (string) Str::uuid(), 'project_id' => $finding->project_id, 'project_assessment_id' => $finding->project_assessment_id, 'finding_id' => $finding->id, 'evidence_file_id' => $evidence->id]);
        $migration = require database_path('migrations/2026_10_11_070610_extend_finding_origins.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('findings', 'origin_type'));
        $this->assertDatabaseHas('findings', ['id' => $finding->id, 'assessment_question_id' => $finding->assessment_question_id]);
        $migration->up();
        $this->assertSame('assessment_question', DB::table('findings')->where('id', $finding->id)->value('origin_type'));
        $this->assertSame($evidence->id, $finding->fresh()->evidenceFiles()->sole()->id);
    }

    public function test_completed_exercise_history_and_children_cannot_be_changed_or_reparented(): void
    {
        [$project, $actor] = $this->bcmProject();
        $result = $this->exerciseResult($project, $actor);
        $exercise = $result->exercise;
        $criterion = $result->criterion;
        $evidence = EvidenceFile::factory()->create(['project_id' => $project->id]);
        $link = BcmExerciseEvidenceLink::query()->create(['project_id' => $project->id, 'bcm_exercise_id' => $exercise->id, 'evidence_id' => $evidence->id]);
        $exercise->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        $other = $this->exerciseResult($project, $actor)->exercise;
        foreach ([
            fn () => $exercise->update(['scenario' => 'changed']),
            fn () => $exercise->delete(),
            fn () => $result->update(['outcome' => 'passed']),
            fn () => $result->delete(),
            fn () => $criterion->update(['expected_result' => 'changed']),
            fn () => $criterion->delete(),
            fn () => $link->delete(),
            fn () => $link->update(['bcm_exercise_id' => $other->id]),
            fn () => BcmExerciseCriterion::query()->create(['project_id' => $project->id, 'bcm_exercise_id' => $exercise->id, 'position' => 2]),
            fn () => $other->criteria()->sole()->update(['bcm_exercise_id' => $exercise->id, 'position' => 2]),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Expected immutable completed history.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $this->assertSame('failed', $result->fresh()->outcome->value);
        $this->assertSame('Restore service', $criterion->fresh()->expected_result);
        $this->assertSame($exercise->id, $link->fresh()->bcm_exercise_id);
    }

    public function test_finding_provenance_cannot_be_rewritten_after_creation(): void
    {
        [$project, $actor] = $this->bcmProject();
        $finding = Finding::query()->create($this->findingData($project, $this->exerciseResult($project, $actor), $actor));
        $other = $this->exerciseResult($project, $actor);
        $this->rejectSql(fn () => DB::table('findings')->where('id', $finding->id)->update(['bcm_exercise_result_id' => $other->id]));
        $this->assertSame($finding->bcm_exercise_result_id, $finding->fresh()->bcm_exercise_result_id);
    }

    public function test_measure_creation_rechecks_stale_actor_for_exercise_origin(): void
    {
        [$project, $actor] = $this->bcmProject();
        $finding = Finding::query()->create([...$this->findingData($project, $this->exerciseResult($project, $actor), $actor), 'status' => 'accepted']);
        $actor->load('organization');
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(MeasureWorkflow::class)->create($finding, ['title' => 'Unauthorized', 'description' => 'Gap', 'priority' => 'high', 'responsible_name' => 'Owner', 'due_date' => '2026-12-01'], $actor);
    }

    public function test_origin_rollback_refuses_bcm_findings_before_any_schema_or_data_change(): void
    {
        [$project, $actor] = $this->bcmProject();
        $finding = Finding::query()->create($this->findingData($project, $this->exerciseResult($project, $actor), $actor));
        $migration = require database_path('migrations/2026_10_11_070610_extend_finding_origins.php');
        try {
            DB::transaction(fn () => $migration->down());
            $this->fail('Expected safe rollback refusal.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('BCM', $exception->getMessage());
        }
        $this->assertDatabaseHas('findings', ['id' => $finding->id, 'origin_type' => 'bcm_exercise_result']);
        $this->assertTrue(Schema::hasColumn('evidence_finding_links', 'project_assessment_id'));
    }

    private function exerciseResult(IsmsProject $project, User $actor): BcmExerciseResult
    {
        $plan = $this->approvedPlan($project, $actor);
        $exercise = BcmExercise::query()->create(['project_id' => $project->id, 'continuity_plan_revision_id' => $plan->id, 'created_by' => $actor->id, 'status' => 'conducted']);
        $criterion = BcmExerciseCriterion::query()->create(['project_id' => $project->id, 'bcm_exercise_id' => $exercise->id, 'position' => 1, 'expected_result' => 'Restore service']);

        return BcmExerciseResult::query()->create(['project_id' => $project->id, 'bcm_exercise_id' => $exercise->id, 'bcm_exercise_criterion_id' => $criterion->id, 'outcome' => 'failed', 'observations' => 'Service unavailable']);
    }

    private function findingData(IsmsProject $project, BcmExerciseResult $result, User $actor): array
    {
        return ['id' => (string) Str::uuid(), 'project_id' => $project->id, 'origin_type' => 'bcm_exercise_result', 'project_assessment_id' => null, 'assessment_question_id' => null, 'bcm_exercise_result_id' => $result->id, 'title' => 'Recovery gap', 'description' => 'Observed gap', 'severity' => 'high', 'status' => 'proposed', 'proposed_by' => $actor->id, 'proposed_at' => now()];
    }

    private function rejectSql(callable $operation, ?string $sqlState = null): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected SQL provenance constraint failure.');
        } catch (QueryException $exception) {
            if ($sqlState !== null) {
                $this->assertSame($sqlState, (string) $exception->getCode());
            } else {
                $this->assertStringStartsWith('23', (string) $exception->getCode());
            }
        }
    }
}
