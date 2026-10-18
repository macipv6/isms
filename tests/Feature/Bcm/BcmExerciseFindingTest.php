<?php

namespace Tests\Feature\Bcm;

use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Models\User;
use App\Services\Bcm\BcmExerciseWorkflow;
use App\Services\Findings\FindingWorkflow;
use App\Services\Measures\MeasureWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class BcmExerciseFindingTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_explicit_proposal_is_idempotent_conflicts_preserve_data_and_completion_never_accepts_it(): void
    {
        [$exercise, $result, $actor] = $this->conductedResult();
        $w = app(FindingWorkflow::class);
        $finding = $w->proposeFromExercise($result, $this->findingData(), $actor);
        $this->assertSame($finding->id, $w->proposeFromExercise($result, $this->findingData(), $actor)->id);
        $this->assertNull($finding->assessment_question_id);
        $this->assertNull($finding->project_assessment_id);
        $this->assertSame($result->id, $finding->bcm_exercise_result_id);
        try {
            $w->proposeFromExercise($result, $this->findingData() + ['ignored' => true] + [], $actor);
            $w->proposeFromExercise($result, [...$this->findingData(), 'title' => 'Conflicting'], $actor);
            $this->fail('Conflicting duplicate accepted.');
        } catch (ConflictHttpException) {
            $this->assertDatabaseCount('findings', 1);
        }
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'finding.proposed')->count());
        $this->assertSame('completed', app(BcmExerciseWorkflow::class)->complete($exercise, 3, $actor)->status->value);
        $this->assertSame(FindingStatus::Proposed, $finding->fresh()->status);
        $this->assertDatabaseCount('measures', 0);
        $finding = $w->decide($finding, FindingStatus::Accepted, null, $actor);
        $this->assertSame(FindingStatus::Accepted, $finding->status);
    }

    public function test_rejected_proposal_history_counts_and_existing_multiple_measure_lifecycle_remains_explicit(): void
    {
        [$exercise, $result, $actor] = $this->conductedResult();
        $w = app(FindingWorkflow::class);
        $rejected = $w->decide($w->proposeFromExercise($result, $this->findingData(), $actor), FindingStatus::Rejected, 'Reasoned rejection', $actor);
        $finding = $w->proposeFromExercise($result, $this->findingData(), $actor);
        $this->assertNotSame($rejected->id, $finding->id);
        $finding = $w->decide($finding, FindingStatus::Accepted, null, $actor);
        $mw = app(MeasureWorkflow::class);
        $measures = [];
        foreach (['Restore', 'Retest'] as $title) {
            $measure = $mw->create($finding, ['title' => $title, 'description' => 'Correct recovery gap', 'priority' => 'high', 'responsible_name' => 'Owner', 'due_date' => '2026-12-01'], $actor);
            $measures[] = $mw->transition($measure, MeasureStatus::InProgress, null, $actor);
        }
        try {
            $w->close($finding, $actor);
            $this->fail('Open measures allowed closure.');
        } catch (ValidationException) {
            $this->assertSame(FindingStatus::Accepted, $finding->fresh()->status);
        }
        $mw->transition($measures[0], MeasureStatus::Completed, null, $actor);
        $mw->transition($measures[1], MeasureStatus::Cancelled, 'Superseded correction', $actor);
        $this->assertSame(FindingStatus::Closed, $w->close($finding, $actor)->status);
        $this->assertSame('completed', app(BcmExerciseWorkflow::class)->complete($exercise, 3, $actor)->status->value);
        $this->assertDatabaseCount('findings', 2);
        $this->expectException(ConflictHttpException::class);
        $w->proposeFromExercise($result, $this->findingData(), $actor);
    }

    public function test_proposal_or_waiver_blocks_result_edits_that_could_erase_explicit_provenance(): void
    {
        [$exercise, $result, $actor] = $this->conductedResult();
        $w = app(BcmExerciseWorkflow::class);
        $w->waiveFinding($result, 'Reviewed no finding needed', 3, $actor);
        $this->assertSame('completed', $w->complete($exercise, 4, $actor)->status->value);
        $this->expectException(ConflictHttpException::class);
        $w->waiveFinding($result, 'Changed waiver', 5, $actor);
    }

    public function test_record_changes_clear_waiver_and_finding_history_freezes_result_payload(): void
    {
        [$exercise, $result, $actor] = $this->conductedResult();
        $w = app(BcmExerciseWorkflow::class);
        $w->waiveFinding($result, 'No correction needed', 3, $actor);
        $exercise = $w->record($exercise, ['results' => [['criterion_id' => $result->bcm_exercise_criterion_id, 'observations' => 'Updated observations']]], 4, $actor);
        $this->assertNull($result->fresh()->finding_waived_at);
        app(FindingWorkflow::class)->proposeFromExercise($result, $this->findingData(), $actor);
        $this->expectException(ConflictHttpException::class);
        $w->record($exercise, ['results' => [['criterion_id' => $result->bcm_exercise_criterion_id, 'outcome' => 'passed']]], 5, $actor);
    }

    public function test_passed_waived_or_nonconducted_results_cannot_receive_proposals_and_stale_actor_is_denied(): void
    {
        [$exercise, $result, $actor] = $this->conductedResult();
        $w = app(FindingWorkflow::class);
        foreach (['passed', null] as $outcome) {
            $result->update(['outcome' => $outcome]);
            try {
                $w->proposeFromExercise($result, $this->findingData(), $actor);
                $this->fail('Ineligible outcome proposed.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('findings', 0);
            }
        }
        $result->update(['outcome' => 'failed']);
        $exercise->update(['status' => 'planned']);
        try {
            $w->proposeFromExercise($result, $this->findingData(), $actor);
            $this->fail('Nonconducted result proposed.');
        } catch (ConflictHttpException) {
            $this->assertDatabaseCount('findings', 0);
        }
        $exercise->update(['status' => 'conducted']);
        app(BcmExerciseWorkflow::class)->waiveFinding($result, 'No finding required', 3, $actor);
        try {
            $w->proposeFromExercise($result, $this->findingData(), $actor);
            $this->fail('Waived result proposed.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('findings', 0);
        }
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $w->proposeFromExercise($result, $this->findingData(), $actor);
    }

    private function conductedResult(): array
    {
        [$project, $actor] = $this->bcmProject();
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($this->approvedPlan($project, $actor), ['scenario' => 'Outage', 'exercise_type' => 'recovery_test', 'planned_at' => '2026-10-15', 'leader_name' => 'Leader', 'participants' => 'Operators', 'criteria' => [['expected_result' => 'Restore service']]], $actor);
        $exercise = $w->plan($exercise, 1, $actor);
        $exercise = $w->record($exercise, ['actual_at' => '2026-10-15', 'results' => [['criterion_id' => $exercise->criteria()->sole()->id, 'outcome' => 'failed', 'observations' => 'Recovery failed', 'reason' => 'Unavailable backup']]], 2, $actor);

        return [$exercise, $exercise->results()->sole(), $actor];
    }

    private function findingData(): array
    {
        return ['title' => 'Recovery gap', 'description' => 'Observed service recovery gap', 'severity' => 'high'];
    }
}
