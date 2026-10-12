<?php

namespace Tests\Feature\Bcm;

use App\Models\BcmExercise;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\EvidenceFile;
use App\Models\User;
use App\Services\Bcm\BcmExerciseWorkflow;
use App\Services\Bcm\BcmFreshness;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use App\Services\Bcm\ContinuityPlanWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class BcmExerciseTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_complete_records_original_plan_results_and_private_audit_without_artificial_findings(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $proof = EvidenceFile::factory()->create(['project_id' => $project->id, 'status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($plan, $this->exerciseData() + ['source_snapshot' => ['spoof' => true], 'completed_by' => $actor->id, 'evidence_ids' => [$proof->id]], $actor);
        $this->assertSame(1, $exercise->lock_version);
        $exercise = $w->plan($exercise, 1, $actor);
        $this->assertSame($plan->id, $exercise->source_snapshot['plan_revision_id']);
        $exercise = $w->record($exercise, $this->resultData($exercise), 2, $actor);
        $this->assertSame('conducted', $exercise->status->value);
        $exercise = $w->complete($exercise, 3, $actor);
        $this->assertSame('completed', $exercise->status->value);
        $this->assertSame($actor->id, $exercise->completed_by);
        $this->assertNotNull($exercise->completed_at);
        $this->assertSame($plan->id, $exercise->continuity_plan_revision_id);
        $this->assertDatabaseCount('findings', 0);
        $this->assertDatabaseCount('project_assessments', 0);
        $this->assertTrue(app(BcmFreshness::class)->exercise($exercise)['current']);
        $audit = json_encode(DB::table('audit_events')->where('event_type', 'like', 'bcm.exercise.%')->get());
        $this->assertStringNotContainsString('Secret', $audit);
        $this->assertStringNotContainsString('spoof', $audit);
        $this->assertSame(4, $exercise->lock_version);
        $proof->update(['status' => 'rejected']);
        $this->assertFalse(app(BcmFreshness::class)->exercise($exercise)['current']);
        $this->assertSame('completed', $exercise->fresh()->status->value);
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_state_transitions_reject_invalid_sources_atomically(string $status, string $action): void
    {
        [$project, $actor] = $this->bcmProject();
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($this->approvedPlan($project, $actor), $this->exerciseData(), $actor);
        $exercise->update(['status' => $status]);
        $audits = DB::table('audit_events')->count();
        try {
            match ($action) {
                'save' => $w->save($exercise, ['scenario' => 'Changed'], 1, $actor),
                'plan' => $w->plan($exercise, 1, $actor),
                'record' => $w->record($exercise, $this->resultData($exercise), 1, $actor),
                'complete' => $w->complete($exercise, 1, $actor),
                'cancel' => $w->cancel($exercise, 'Reason', 1, $actor),
            };
            $this->fail('Invalid status action succeeded.');
        } catch (ConflictHttpException) {
            $this->assertSame($status, $exercise->fresh()->status->value);
            $this->assertSame($audits, DB::table('audit_events')->count());
            $this->assertSame(1, $exercise->fresh()->lock_version);
        }
    }

    public static function forbiddenTransitions(): array
    {
        return [['planned', 'save'], ['conducted', 'save'], ['draft', 'record'], ['draft', 'complete'], ['planned', 'complete'], ['planned', 'plan'], ['conducted', 'plan'], ['completed', 'record'], ['completed', 'cancel'], ['cancelled', 'save'], ['cancelled', 'complete'], ['cancelled', 'plan']];
    }

    public function test_planning_requires_complete_fields_criteria_and_current_approved_sources(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($plan, [], $actor);
        try {
            $w->plan($exercise, 1, $actor);
            $this->fail('Incomplete exercise planned.');
        } catch (ValidationException) {
            $this->assertSame('draft', $exercise->fresh()->status->value);
        }
        $exercise = $w->save($exercise, $this->exerciseData(), 1, $actor);
        $same = $w->save($exercise, $this->exerciseData(), 2, $actor);
        $this->assertSame(2, $same->lock_version);
        $draft = app(ContinuityPlanWorkflow::class)->revise($plan, $actor);
        $this->assertTrue(app(BcmFreshness::class)->plan($plan)['current']);
        app(ContinuityPlanWorkflow::class)->approve($draft, 1, $actor);
        $this->expectException(ValidationException::class);
        $w->plan($exercise, 2, $actor);
    }

    public function test_stale_planned_exercise_cannot_start_but_conducted_history_requires_recorded_acknowledgement(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $w = app(BcmExerciseWorkflow::class);
        $planned = $w->plan($w->create($plan, $this->exerciseData(), $actor), 1, $actor);
        $conducted = $w->plan($w->create($plan, $this->exerciseData(), $actor), 1, $actor);
        $conducted = $w->record($conducted, $this->resultData($conducted), 2, $actor);
        $pinned = $conducted->source_snapshot;
        app(BiaConfigurationWorkflow::class)->activate($project, [60, 240], 1, $actor);
        try {
            $w->record($planned, $this->resultData($planned) + ['source_change_reason' => 'Acknowledged'], 2, $actor);
            $this->fail('Stale planned exercise started.');
        } catch (ValidationException) {
            $this->assertSame('planned', $planned->fresh()->status->value);
            $this->assertDatabaseCount('bcm_exercise_results', 1);
        }
        try {
            $w->complete($conducted, 3, $actor);
            $this->fail('Missing source acknowledgement allowed.');
        } catch (ValidationException) {
            $this->assertNull($conducted->fresh()->completed_at);
        }
        $conducted = $w->record($conducted, ['source_change_reason' => 'Secret: retain original plan after review'], 3, $actor);
        $this->assertSame($actor->id, $conducted->source_change_acknowledged_by);
        $this->assertNotNull($conducted->source_change_acknowledged_at);
        $this->assertSame($pinned['plan_sources'], $conducted->source_snapshot['plan_sources']);
        app(BiaConfigurationWorkflow::class)->activate($project, [60, 480], 2, $actor);
        try {
            $w->complete($conducted, 4, $actor);
            $this->fail('Old acknowledgement covered a later source change.');
        } catch (ValidationException) {
            $this->assertSame(4, $conducted->fresh()->lock_version);
        }
        $conducted = $w->record($conducted, ['source_change_reason' => 'Secret: second source review'], 4, $actor);
        $completed = $w->complete($conducted, 5, $actor);
        $freshness = app(BcmFreshness::class);
        $this->assertFalse($freshness->exercise($completed)['current']);
        $this->assertSame('completed', $completed->fresh()->status->value);
        $this->assertSame($plan->id, $completed->continuity_plan_revision_id);
    }

    public function test_completion_requires_actual_date_all_observations_and_nonpassed_treatment(): void
    {
        [$project, $actor] = $this->bcmProject();
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->plan($w->create($this->approvedPlan($project, $actor), $this->exerciseData(), $actor), 1, $actor);
        $exercise = $w->record($exercise, [], 2, $actor);
        try {
            $w->complete($exercise, 3, $actor);
            $this->fail('Incomplete exercise completed.');
        } catch (ValidationException) {
            $this->assertSame('conducted', $exercise->fresh()->status->value);
        }
        $exercise = $w->record($exercise, $this->resultData($exercise, 'not_tested'), 3, $actor);
        try {
            $w->complete($exercise, 4, $actor);
            $this->fail('Not tested result completed without treatment.');
        } catch (ValidationException) {
            $this->assertSame(4, $exercise->fresh()->lock_version);
        }
        $result = $exercise->results()->sole();
        $w->waiveFinding($result, 'Secret: omitted with reason', 4, $actor);
        $this->assertSame($actor->id, $result->fresh()->finding_waived_by);
        $this->assertNotNull($result->fresh()->finding_waived_at);
        $completed = $w->complete($exercise, 5, $actor);
        $this->assertSame('completed', $completed->status->value);
        $this->assertSame('not_tested', $result->fresh()->outcome->value);
    }

    public function test_new_drafts_and_unrelated_approved_sources_do_not_invalidate_exercise(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->plan($w->create($plan, $this->exerciseData(), $actor), 1, $actor);
        app(ContinuityPlanWorkflow::class)->revise($plan, $actor);
        $other = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $bia = app(BiaWorkflow::class);
        $config = app(BiaConfigurationWorkflow::class)->current($project);
        $bia->approve($bia->save($bia->draft($other, $actor), $this->completeBiaData($config), 1, $actor), 2, $actor);
        $this->assertTrue(app(BcmFreshness::class)->exercise($exercise)['current']);
        $exercise = $w->record($exercise, $this->resultData($exercise), 2, $actor);
        $this->assertSame('completed', $w->complete($exercise, 3, $actor)->status->value);
    }

    public function test_conducted_exercise_can_acknowledge_deactivated_sources_and_complete_on_original_plan(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->plan($w->create($plan, $this->exerciseData(), $actor), 1, $actor);
        $exercise = $w->record($exercise, $this->resultData($exercise), 2, $actor);
        $plan->processes()->sole()->process->update(['is_active' => false]);
        $exercise = $w->record($exercise, ['source_change_reason' => 'Historical exercise performed before deactivation'], 3, $actor);
        app(BiaConfigurationWorkflow::class)->activate($project, [60, 240], 1, $actor);
        try {
            $w->complete($exercise, 4, $actor);
            $this->fail('Deactivated root concealed a subsequent configuration change.');
        } catch (ValidationException) {
            $this->assertSame(4, $exercise->fresh()->lock_version);
        }
        $exercise = $w->record($exercise, ['source_change_reason' => 'Reviewed later configuration change'], 4, $actor);
        $completed = $w->complete($exercise, 5, $actor);
        $this->assertSame('completed', $completed->status->value);
        $this->assertFalse(app(BcmFreshness::class)->exercise($completed)['current']);
        $this->assertSame($plan->id, $completed->continuity_plan_revision_id);
    }

    public function test_acknowledgement_covers_successor_heads_of_deactivated_reachable_processes(): void
    {
        [$project, $actor] = $this->bcmProject();
        $original = $this->approvedPlan($project, $actor);
        $root = $original->processes()->sole()->process;
        $target = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $bia = app(BiaWorkflow::class);
        $bia->approve($bia->save($bia->draft($target, $actor), $this->completeBiaData(app(BiaConfigurationWorkflow::class)->current($project)), 1, $actor), 2, $actor);
        DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $root->id, 'target_process_id' => $target->id, 'target_asset_id' => null, 'importance' => 'critical']);
        $bia->approve($bia->draft($root, $actor), 1, $actor);
        $pw = app(ContinuityPlanWorkflow::class);
        $plan = $pw->approve($pw->revise($original, $actor), 1, $actor);
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->plan($w->create($plan, $this->exerciseData(), $actor), 1, $actor);
        $exercise = $w->record($exercise, $this->resultData($exercise), 2, $actor);
        $target->update(['is_active' => false]);
        $exercise = $w->record($exercise, ['source_change_reason' => 'Target deactivated after exercise'], 3, $actor);
        $target->update(['is_active' => true]);
        $bia->approve($bia->draft($target, $actor), 1, $actor);
        $target->update(['is_active' => false]);
        try {
            $w->complete($exercise, 4, $actor);
            $this->fail('Inactive target concealed successor approval.');
        } catch (ValidationException) {
            $this->assertSame(4, $exercise->fresh()->lock_version);
        }
        $exercise = $w->record($exercise, ['source_change_reason' => 'Reviewed target successor'], 4, $actor);
        $this->assertSame('completed', $w->complete($exercise, 5, $actor)->status->value);
    }

    #[DataProvider('invalidInputs')]
    public function test_malformed_lists_rows_and_native_minute_values_are_rejected(array $data): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $this->expectException(ValidationException::class);
        app(BcmExerciseWorkflow::class)->create($plan, $data, $actor);
    }

    public static function invalidInputs(): array
    {
        return [[['criteria' => 'invalid']], [['criteria' => ['expected_result' => 'not a list']]], [['criteria' => ['invalid row']]], [['criteria' => [['expected_minutes' => '60']]]], [['criteria' => [['expected_minutes' => '']]]], [['criteria' => [['expected_minutes' => -1]]]], [['criteria' => [['unexpected' => 'value']]]]];
    }

    public function test_complete_requires_observations_and_reason_for_every_result_and_waiver_rejects_blank_or_passed(): void
    {
        [$project, $actor] = $this->bcmProject();
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->plan($w->create($this->approvedPlan($project, $actor), $this->exerciseData(), $actor), 1, $actor);
        $exercise = $w->record($exercise, $this->resultData($exercise), 2, $actor);
        $result = $exercise->results()->sole();
        foreach (['observations', 'reason'] as $field) {
            $result->update([$field => ' ']);
            try {
                $w->complete($exercise, 3, $actor);
                $this->fail('Incomplete result completed.');
            } catch (ValidationException) {
                $this->assertSame('conducted', $exercise->fresh()->status->value);
            }
            $result->update([$field => 'Complete']);
        }
        try {
            $w->waiveFinding($result, 'Reason', 3, $actor);
            $this->fail('Passed criterion waived.');
        } catch (ValidationException) {
            $this->assertNull($result->fresh()->finding_waived_at);
        }
        $result->update(['outcome' => 'partial']);
        $this->expectException(ValidationException::class);
        $w->waiveFinding($result, ' ', 3, $actor);
    }

    #[DataProvider('badEvidence')]
    public function test_completion_rechecks_linked_evidence_and_rolls_back(string $status, bool $reviewed): void
    {
        [$project, $actor] = $this->bcmProject();
        $proof = EvidenceFile::factory()->create(['project_id' => $project->id, 'status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($this->approvedPlan($project, $actor), $this->exerciseData() + ['evidence_ids' => [$proof->id]], $actor);
        $exercise = $w->record($w->plan($exercise, 1, $actor), $this->resultData($exercise), 2, $actor);
        $proof->update(['status' => $status, 'reviewed_by' => $reviewed ? $actor->id : null]);
        $audits = DB::table('audit_events')->count();
        try {
            $w->complete($exercise, 3, $actor);
            $this->fail('Unverified evidence allowed.');
        } catch (ValidationException) {
            $this->assertSame('conducted', $exercise->fresh()->status->value);
            $this->assertNull($exercise->fresh()->completed_at);
            $this->assertSame($audits, DB::table('audit_events')->count());
        }
    }

    public static function badEvidence(): array
    {
        return [['pending_review', true], ['rejected', true], ['verified', false]];
    }

    public function test_foreign_evidence_and_foreign_nested_criterion_fail_with_no_partial_changes(): void
    {
        [$project, $actor] = $this->bcmProject();
        $w = app(BcmExerciseWorkflow::class);
        $plan = $this->approvedPlan($project, $actor);
        $exercise = $w->create($plan, $this->exerciseData(), $actor);
        $foreign = EvidenceFile::factory()->create();
        try {
            $w->save($exercise, ['scenario' => 'Changed', 'evidence_ids' => [$foreign->id]], 1, $actor);
            $this->fail('Foreign evidence linked.');
        } catch (ModelNotFoundException) {
            $this->assertSame('Secret outage', $exercise->fresh()->scenario);
        }
        $other = $w->create($plan, $this->exerciseData(), $actor);
        $this->expectException(ModelNotFoundException::class);
        $w->record($w->plan($exercise, 1, $actor), $this->resultData($other), 2, $actor);
    }

    public function test_stale_versions_and_fresh_actor_are_checked_and_cancellation_requires_reason(): void
    {
        [$project, $actor] = $this->bcmProject();
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($this->approvedPlan($project, $actor), $this->exerciseData(), $actor);
        foreach ([fn () => $w->save($exercise, [], 0, $actor), fn () => $w->plan($exercise, 2, $actor)] as $action) {
            try {
                $action();
                $this->fail('Stale lock accepted.');
            } catch (ConflictHttpException) {
                $this->assertSame(1, $exercise->fresh()->lock_version);
            }
        }
        try {
            $w->cancel($exercise, ' ', 1, $actor);
            $this->fail('Blank cancellation reason accepted.');
        } catch (ValidationException) {
            $this->assertSame('draft', $exercise->fresh()->status->value);
        }
        $cancelled = $w->cancel($exercise, 'Secret cancellation', 1, $actor);
        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertSame($actor->id, $cancelled->cancelled_by);
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $w->create($exercise->planRevision, [], $actor);
    }

    private function exerciseData(): array
    {
        return ['scenario' => 'Secret outage', 'exercise_type' => 'tabletop', 'planned_at' => '2026-10-15 10:00:00+02:00', 'leader_name' => 'Secret leader', 'participants' => 'Secret participants', 'criteria' => [['expected_result' => 'Restore service', 'expected_minutes' => 60]]];
    }

    private function resultData(BcmExercise $exercise, string $outcome = 'passed'): array
    {
        return ['actual_at' => '2026-10-15 10:15:00+02:00', 'results' => [['criterion_id' => $exercise->criteria()->sole()->id, 'outcome' => $outcome, 'observations' => 'Secret observations', 'reason' => 'Secret result reason', 'actual_minutes' => 50]]];
    }
}
