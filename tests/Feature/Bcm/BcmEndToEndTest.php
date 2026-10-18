<?php

namespace Tests\Feature\Bcm;

use App\Enums\BcmExerciseStatus;
use App\Enums\MeasureStatus;
use App\Models\Asset;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\AuditEvent;
use App\Models\BcmConflictAcceptance;
use App\Models\BcmExercise;
use App\Models\BiaConfigurationVersion;
use App\Models\BiaRevision;
use App\Models\BusinessProcess;
use App\Models\ContinuityPlanRevision;
use App\Models\DependencyEdge;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Services\Bcm\BcmExerciseWorkflow;
use App\Services\Bcm\BcmFreshness;
use App\Services\Measures\MeasureWorkflow;
use App\Services\Registers\AssetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BcmEndToEndTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    #[DataProvider('exerciseInstants')]
    public function test_exercise_datetime_inputs_and_action_metadata_preserve_absolute_instants_across_dst(string $instant, string $planned, string $actual, string $expectedActual): void
    {
        $now = CarbonImmutable::parse($instant);
        $this->travelTo($now);
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $workflow = app(BcmExerciseWorkflow::class);
        $exercise = $workflow->create($plan, ['scenario' => 'Restore', 'exercise_type' => 'tabletop', 'planned_at' => $planned, 'leader_name' => 'Lead', 'participants' => 'Team', 'criteria' => [['expected_result' => 'Restore']]], $actor);
        $exercise = $workflow->plan($exercise, 1, $actor);
        $exercise = $workflow->record($exercise, ['actual_at' => $actual, 'source_change_reason' => 'Sources explicitly reviewed', 'results' => [['criterion_id' => $exercise->criteria()->sole()->id, 'outcome' => 'failed', 'observations' => 'Restore failed', 'reason' => 'Backup missing']]], 2, $actor);
        $result = $workflow->waiveFinding($exercise->results()->sole(), 'Reasoned no finding decision', 3, $actor);
        $exercise = $workflow->complete($exercise, 4, $actor)->fresh();
        $this->assertSame($planned, $exercise->planned_at->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame($expectedActual, $exercise->actual_at->utc()->format('Y-m-d\TH:i:s\Z'));
        foreach ([$plan->fresh()->approved_at, $exercise->created_at, $exercise->completed_at, $exercise->source_change_acknowledged_at, $result->fresh()->finding_waived_at] as $timestamp) {
            $this->assertSame($instant, $timestamp->utc()->format('Y-m-d\TH:i:s\Z'));
        }
        $this->assertSame($actor->id, $result->fresh()->finding_waived_by);
        $this->assertSame($actor->id, $exercise->source_change_acknowledged_by);
        $cancelled = $workflow->cancel($workflow->create($plan, [], $actor), 'Cancelled explicitly', 1, $actor)->fresh();
        $this->assertSame($instant, $cancelled->cancelled_at->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame($actor->id, $cancelled->cancelled_by);
    }

    public static function exerciseInstants(): array
    {
        return [
            'summer UTC and offset' => ['2026-07-15T10:00:00Z', '2026-07-15T07:00:00Z', '2026-07-15T09:30:00+02:00', '2026-07-15T07:30:00Z'],
            'winter UTC and offset' => ['2026-12-15T10:00:00Z', '2026-12-15T07:00:00Z', '2026-12-15T08:30:00+01:00', '2026-12-15T07:30:00Z'],
        ];
    }

    public function test_http_recovery_lifecycle_keeps_decisions_private_and_completed_history_when_sources_change(): void
    {
        // Catches broken cross-module provenance, implicit Finding closure and stale readiness.
        $instant = CarbonImmutable::parse('2026-10-13T10:00:00Z');
        $this->travelTo($instant);
        [$project, $actor] = $this->bcmProject();
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $base = "/projects/{$project->id}/bcm";
        $register = "/organizations/{$project->organization_id}/projects/{$project->id}";
        $this->actingAs($actor)->post($base.'/configuration/initialize')->assertRedirect($base)->assertSessionHasNoErrors();
        $configuration = BiaConfigurationVersion::query()->sole();
        $this->assertSame([60, 240, 480, 1440, 4320, 10080], $configuration->minutes);

        $this->post($base.'/bia', ['business_process_id' => $process->id])->assertRedirect()->assertSessionHasNoErrors();
        $bia = BiaRevision::query()->sole();
        $biaUrl = $base.'/bia/'.$bia->id;
        $biaData = [...$this->completeBiaData($configuration), 'owner_name' => 'Private process owner', 'target_reason' => 'Private target rationale'];
        foreach ($biaData['impacts'] as &$impact) {
            $impact['reason'] = 'Private impact rationale';
        }
        unset($impact);
        $this->put($biaUrl, $biaData + ['lock_version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(24, $bia->impacts()->count());

        $assetUrl = $base.'/assets/'.$asset->id.'/recovery';
        $this->post($assetUrl)->assertRedirect()->assertSessionHasNoErrors();
        $objective = AssetRecoveryObjectiveRevision::query()->sole();
        $this->put($assetUrl.'/'.$objective->id, ['lock_version' => 1, 'rto_minutes' => 300, 'rpo_minutes' => 0, 'target_reason' => 'Private asset rationale'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($assetUrl.'/'.$objective->id.'/approve', ['lock_version' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $edge = DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'source_asset_id' => null, 'target_process_id' => null, 'target_asset_id' => $asset->id, 'importance' => 'critical']);
        $conflicts = $this->get($biaUrl)->assertOk()->viewData('page')['props']['conflicts']['data'];
        $this->assertCount(1, $conflicts);
        $this->assertSame('dependency_rto_exceeds_process', $conflicts[0]['code']);
        $this->assertTrue($conflicts[0]['blocking']);
        $this->assertSame([$edge->id], $conflicts[0]['path_edge_ids']);
        $this->assertSame($objective->id, $conflicts[0]['revision_id']);
        $beforeRefusal = AuditEvent::query()->count();
        $this->post($biaUrl.'/approve', ['lock_version' => 2])->assertSessionHasErrors('conflicts');
        $this->assertSame('draft', $bia->fresh()->status->value);
        $this->assertSame($beforeRefusal, AuditEvent::query()->count());
        $this->post($biaUrl.'/conflicts', ['lock_version' => 2, 'fingerprint' => $conflicts[0]['fingerprint'], 'reason' => 'Private conflict rationale'])->assertRedirect()->assertSessionHasNoErrors();
        $acceptance = BcmConflictAcceptance::query()->sole();
        $this->assertSame($actor->id, $acceptance->accepted_by);
        $this->assertSame($instant->toIso8601String(), $acceptance->accepted_at->utc()->toIso8601String());
        $this->assertSame($objective->id, $acceptance->warning_snapshot['revision_id']);
        $this->post($biaUrl.'/approve', ['lock_version' => 3])->assertRedirect()->assertSessionHasNoErrors();

        $proof = EvidenceFile::factory()->create(['project_id' => $project->id, 'uploaded_by' => $actor->id, 'original_name' => 'Private evidence filename.pdf']);
        $reviewUrl = $register.'/evidence/'.$proof->id.'/review';
        $this->patch($reviewUrl, ['status' => 'verified', 'review_note' => 'Private verification rationale'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($base.'/plans', [
            'title' => 'Private plan title', 'scenario' => 'Private plan scenario',
            'activation_conditions' => 'Private activation conditions', 'strategy' => 'Private recovery strategy',
            'minimum_operation' => 'Private minimum operation', 'normal_operation_return' => 'Private normal return',
            'process_ids' => [$process->id], 'evidence_ids' => [$proof->id],
            'steps' => [['instruction' => 'Private recovery instruction', 'owner_name' => 'Private step owner', 'expected_minutes' => 120]],
            'roles' => [['role' => 'Private role', 'name' => 'Private contact name', 'email' => 'private-contact@example.test', 'phone' => '+49 123 456789', 'deputy_name' => 'Private deputy']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $plan = ContinuityPlanRevision::query()->sole();
        $planUrl = $base.'/plans/'.$plan->id;
        $this->post($planUrl.'/approve', ['lock_version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $plan->refresh();
        $this->assertSame($bia->id, $plan->source_snapshot['processes'][0]['revision_id']);
        $this->assertSame($objective->id, $plan->source_snapshot['assets'][0]['revision_id']);
        $this->assertSame([$proof->id], $plan->source_snapshot['evidence_ids']);

        $this->post($base.'/exercises', [
            'plan_revision_id' => $plan->id, 'scenario' => 'Private exercise scenario', 'exercise_type' => 'recovery_test',
            'planned_at' => '2026-10-13T09:00:00Z', 'leader_name' => 'Private exercise leader', 'participants' => 'Private participants',
            'evidence_ids' => [$proof->id], 'criteria' => [['expected_result' => 'Private expected restoration', 'expected_minutes' => 120]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $exercise = BcmExercise::query()->sole();
        $exerciseUrl = $base.'/exercises/'.$exercise->id;
        $this->post($exerciseUrl.'/plan', ['lock_version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($exerciseUrl.'/record', ['lock_version' => 2, 'actual_at' => '2026-10-13T09:30:00Z', 'results' => [
            ['criterion_id' => $exercise->criteria()->sole()->id, 'outcome' => 'failed', 'observations' => 'Private failed restoration', 'reason' => 'Private missing backup', 'actual_minutes' => 180],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $result = $exercise->results()->sole();
        $this->post($exerciseUrl.'/complete', ['lock_version' => 3])->assertSessionHasErrors();
        $this->assertSame('conducted', $exercise->fresh()->status->value);
        $proposalUrl = $exerciseUrl.'/results/'.$result->id.'/finding';
        $proposal = ['title' => 'Private finding title', 'description' => 'Private correction description', 'severity' => 'high'];
        $this->post($proposalUrl, $proposal)->assertRedirect()->assertSessionHasNoErrors();
        $finding = Finding::query()->sole();
        $this->post($proposalUrl, $proposal)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('findings', 1);
        $this->assertSame('bcm_exercise_result', $finding->origin_type->value);
        $this->assertSame($result->id, $finding->bcm_exercise_result_id);
        $this->assertNull($finding->assessment_question_id);
        $this->assertNull($finding->project_assessment_id);
        $this->assertDatabaseCount('project_assessments', 0);
        $this->patch($register.'/findings/'.$finding->id.'/decision', ['status' => 'accepted', 'decision_note' => 'Private finding decision'])->assertRedirect()->assertSessionHasNoErrors();
        foreach (['Private backup correction', 'Private recovery retest'] as $title) {
            $this->post($register.'/findings/'.$finding->id.'/measures', ['title' => $title, 'description' => 'Private measure description', 'priority' => 'high', 'responsible_name' => 'Private measure owner', 'responsible_email' => 'private-owner@example.test', 'due_date' => '2026-11-01'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame(2, $finding->measures()->count());
        $this->post($exerciseUrl.'/complete', ['lock_version' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $exercise->fresh()->status->value);
        $this->assertSame('accepted', $finding->fresh()->status->value);
        $this->assertSame([MeasureStatus::Planned, MeasureStatus::Planned], $finding->measures()->pluck('status')->all());
        $this->assertSame('failed', $result->fresh()->outcome->value);

        $exercise->refresh();
        $finding->refresh();
        foreach ([$configuration->fresh(), $objective->fresh(), $bia->fresh(), $plan] as $released) {
            $this->assertSame($actor->id, $released->approved_by);
            $this->assertTrue($instant->equalTo($released->approved_at));
        }
        $this->assertSame($actor->id, $exercise->fresh()->completed_by);
        $this->assertTrue($instant->equalTo($exercise->completed_at));
        $this->assertSame($actor->id, $finding->fresh()->proposed_by);
        $this->assertSame($actor->id, $finding->decided_by);
        $this->assertTrue($instant->equalTo($finding->proposed_at));
        $this->assertTrue($instant->equalTo($finding->decided_at));
        $this->assertSame([$actor->id, $actor->id], $finding->measures()->pluck('created_by')->all());
        $this->assertTrue($instant->equalTo($proof->fresh()->reviewed_at));
        foreach ([$bia->analysis, ...$bia->impacts()->get(), $plan->plan, ...$plan->steps()->get(), ...$plan->roles()->get(), ...$plan->processes()->get(), ...$plan->evidenceLinks()->get(), ...$exercise->criteria()->get(), ...$exercise->evidenceLinks()->get(), $result, ...$finding->measures()->get()] as $model) {
            $this->assertTrue($instant->equalTo($model->fresh()->created_at), $model::class.' creation instant changed.');
        }
        $measure = $finding->measures()->firstOrFail();
        $measures = app(MeasureWorkflow::class);
        $measure = $measures->transition($measure, MeasureStatus::InProgress, null, $actor);
        $measure = $measures->transition($measure, MeasureStatus::Completed, null, $actor)->fresh();
        $this->assertSame($actor->id, $measure->completed_by);
        $this->assertTrue($instant->equalTo($measure->completed_at));
        $this->assertSame('2026-11-01', $measure->due_date->format('Y-m-d'));
        $this->assertSame($plan->id, $exercise->source_snapshot['plan_revision_id']);
        $this->assertEquals($plan->source_snapshot, $exercise->source_snapshot['plan_sources']);

        $history = [$configuration, $objective, $bia, $acceptance, $plan, $exercise, $result, $finding];
        $original = array_map(fn ($model) => $model->fresh()->getAttributes(), $history);
        $this->assertReadiness($base, 0, 0, 0);
        $this->patch($reviewUrl, ['status' => 'rejected', 'review_note' => 'Private verification withdrawal'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertReadiness($base, 0, 1, 1);
        $this->assertContains('evidence_unverified', app(BcmFreshness::class)->exercise($exercise)['reasons']);
        $this->patch($reviewUrl, ['status' => 'verified', 'review_note' => 'Private renewed verification'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertReadiness($base, 0, 0, 0);

        // Identical numbers with a new approved source invalidate readiness and acceptance.
        $this->post($assetUrl)->assertRedirect()->assertSessionHasNoErrors();
        $successor = AssetRecoveryObjectiveRevision::query()->where('version', 2)->sole();
        $this->post($assetUrl.'/'.$successor->id.'/approve', ['lock_version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertReadiness($base, 1, 1, 1);
        $biaPage = $this->get($biaUrl)->assertOk()->viewData('page')['props'];
        $this->assertSame($objective->id, $biaPage['sources']['data'][0]['revision_id']);
        $this->assertFalse($biaPage['conflicts']['data'][0]['accepted']);
        $this->assertNotSame($acceptance->fingerprint, $biaPage['conflicts']['data'][0]['fingerprint']);
        app(AssetService::class)->changeStatus($asset, false, $actor);
        $this->assertReadiness($base, 1, 1, 1);
        $this->assertContains('asset_inactive', app(BcmFreshness::class)->plan($plan)['reasons']);
        $planPage = $this->get($planUrl)->assertOk()->viewData('page')['props'];
        $this->assertContains($objective->id, array_column($planPage['sources']['data'], 'revision_id'));
        $exercisePage = $this->get($exerciseUrl)->assertOk()->viewData('page')['props'];
        $this->assertSame(BcmExerciseStatus::Completed, $exercisePage['exercise']['status']);
        $this->assertFalse($exercisePage['capabilities']['record']);
        $this->assertSame($plan->id, $exercisePage['exercise']['plan_revision_id']);
        $this->assertSame($original, array_map(fn ($model) => $model->fresh()->getAttributes(), $history));

        $events = AuditEvent::query()->where('organization_id', $project->organization_id)->get();
        foreach (['bcm.configuration.activated', 'bcm.conflict.accepted', 'bcm.bia.approved', 'bcm.asset_recovery.approved', 'bcm.plan.approved', 'bcm.exercise.completed', 'finding.proposed', 'finding.accepted', 'measure.created'] as $type) {
            $this->assertTrue($events->contains('event_type', $type), 'Missing audit: '.$type);
        }
        $this->assertSame(1, $events->where('event_type', 'finding.proposed')->count());
        $this->assertSame(2, $events->where('event_type', 'measure.created')->count());
        foreach ($events as $event) {
            $this->assertSame($actor->id, $event->actor_user_id);
            $this->assertTrue($instant->equalTo($event->occurred_at));
            $this->assertSame($project->id, $event->context['project_id']);
            $this->assertSame([], array_diff(array_keys($event->context), ['project_id', 'source_id', 'target_id', 'decision_id', 'evidence_id', 'finding_id', 'measure_id', 'asset_id', 'key', 'source_type', 'old_status', 'new_status', 'old_active', 'new_active', 'changed_fields']));
            $serialized = json_encode($event->context, JSON_THROW_ON_ERROR);
            foreach (['Private', 'private-contact@example.test', 'private-owner@example.test', '+49 123 456789'] as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
        }
    }

    private function assertReadiness(string $base, int $bia, int $plan, int $exercise): void
    {
        $events = AuditEvent::query()->count();
        $overview = $this->get($base)->assertOk()->viewData('page')['props']['overview'];
        $this->assertSame($bia, $overview['bia_review_required']);
        $this->assertSame($plan, $overview['plan_review_required']);
        $this->assertSame($exercise, $overview['exercise_review_required']);
        $this->assertSame(1, $overview['exercises']['completed']);
        $this->assertSame($events, AuditEvent::query()->count());
    }
}
