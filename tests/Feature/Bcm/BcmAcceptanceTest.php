<?php

namespace Tests\Feature\Bcm;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\BcmConflictAcceptance;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\User;
use App\Services\Bcm\AssetRecoveryWorkflow;
use App\Services\Bcm\BcmConflictWorkflow;
use App\Services\Bcm\BcmConsistencyChecker;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class BcmAcceptanceTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_blocking_warning_requires_fresh_reasoned_acceptance_before_approval(): void
    {
        [$bia, $actor] = $this->conflict();
        $warning = app(BcmConsistencyChecker::class)->warnings($bia)[0];
        try {
            app(BiaWorkflow::class)->approve($bia, 2, $actor);
            $this->fail('Unresolved conflict approved');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conflicts', $exception->errors());
        }
        foreach (['', '   '] as $reason) {
            try {
                app(BcmConflictWorkflow::class)->accept($bia, $warning->fingerprint, $reason, 2, $actor);
                $this->fail('Blank decision accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reason', $exception->errors());
            }
        }
        $acceptance = app(BcmConflictWorkflow::class)->accept($bia, $warning->fingerprint, 'Reviewed recovery workaround', 2, $actor);
        $this->assertSame($warning->fingerprint, $acceptance->fingerprint);
        $this->assertSame($actor->id, $acceptance->accepted_by);
        $this->assertSame(3, $bia->fresh()->lock_version);
        $approved = app(BiaWorkflow::class)->approve($bia, 3, $actor);
        $this->assertSame('approved', $approved->status->value);
        $this->assertSame([$warning->fingerprint], $approved->source_snapshot['warning_fingerprints']);
    }

    public function test_source_successor_and_path_change_reject_stale_decisions_without_mutation(): void
    {
        [$bia, $actor, $asset, $edge] = $this->conflict();
        $old = app(BcmConsistencyChecker::class)->warnings($bia)[0]->fingerprint;
        app(BcmConflictWorkflow::class)->accept($bia, $old, 'Initial decision', 2, $actor);
        $workflow = app(AssetRecoveryWorkflow::class);
        $next = $workflow->draft($asset, $actor);
        $workflow->approve($next, 1, $actor);
        $new = app(BcmConsistencyChecker::class)->warnings($bia)[0]->fingerprint;
        $this->assertNotSame($old, $new);
        $auditCount = AuditEvent::query()->count();
        foreach ([$old, str_repeat('a', 64)] as $fingerprint) {
            try {
                app(BcmConflictWorkflow::class)->accept($bia, $fingerprint, 'Stale decision', 3, $actor);
                $this->fail('Stale decision accepted');
            } catch (ConflictHttpException) {
                $this->assertSame(1, BcmConflictAcceptance::query()->count());
                $this->assertSame(3, $bia->fresh()->lock_version);
                $this->assertSame($auditCount, AuditEvent::query()->count());
            }
        }
        try {
            app(BiaWorkflow::class)->approve($bia, 3, $actor);
            $this->fail('Old acceptance reused for new source');
        } catch (ValidationException) {
            $this->assertSame('draft', $bia->fresh()->status->value);
        }
        $edge->update(['importance' => 'supporting']);
        $this->assertNotSame($new, app(BcmConsistencyChecker::class)->warnings($bia)[0]->fingerprint);
        try {
            app(BcmConflictWorkflow::class)->accept($bia, $new, 'Changed path', 3, $actor);
            $this->fail('Old critical path decision accepted');
        } catch (ConflictHttpException) {
            $this->assertSame(1, BcmConflictAcceptance::query()->count());
            $this->assertSame(3, $bia->fresh()->lock_version);
        }
    }

    public function test_accepted_impact_hints_cannot_override_invalid_rto_mtpd(): void
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $workflow = app(BiaWorkflow::class);
        $bia = $workflow->draft($process, $actor);
        $data = $this->completeBiaData($configuration);
        $data['impacts'][0]['level'] = 4;
        $bia = $workflow->save($bia, $data, 1, $actor);
        $warnings = app(BcmConsistencyChecker::class)->warnings($bia);
        $this->assertSame(['mtpd_after_critical_impact', 'descending_impact'], array_column($warnings, 'code'));
        foreach ($warnings as $warning) {
            app(BcmConflictWorkflow::class)->accept($bia, $warning->fingerprint, 'Expert review', $bia->fresh()->lock_version, $actor);
        }
        $bia = $workflow->save($bia, ['rto_minutes' => 600], $bia->fresh()->lock_version, $actor);
        $this->expectException(ValidationException::class);
        $workflow->approve($bia, $bia->lock_version, $actor);
    }

    public function test_decision_provenance_is_immutable_and_audit_excludes_reason(): void
    {
        [$bia, $actor] = $this->conflict();
        $fingerprint = app(BcmConsistencyChecker::class)->warnings($bia)[0]->fingerprint;
        $decision = app(BcmConflictWorkflow::class)->accept($bia, $fingerprint, 'Private justification', 2, $actor);
        $this->assertArrayHasKey('sources', $decision->warning_snapshot);
        $event = AuditEvent::query()->where('event_type', 'bcm.conflict.accepted')->firstOrFail();
        $this->assertSame($decision->id, $event->context['decision_id']);
        $this->assertStringNotContainsString('Private justification', $event->toJson());
        foreach (['update', 'delete'] as $action) {
            try {
                DB::transaction(fn () => $action === 'update' ? $decision->update(['reason' => 'Changed']) : $decision->delete());
                $this->fail('Decision history mutated');
            } catch (QueryException) {
                $this->assertDatabaseHas('bcm_conflict_acceptances', ['id' => $decision->id, 'reason' => 'Private justification']);
            }
        }
        app(BiaWorkflow::class)->approve($bia, 3, $actor);
        $this->expectException(ConflictHttpException::class);
        app(BcmConflictWorkflow::class)->accept($bia, $fingerprint, 'Approved history', 4, $actor);
    }

    public function test_acceptance_reloads_actor_and_rejects_stale_lock(): void
    {
        [$bia, $actor] = $this->conflict();
        $fingerprint = app(BcmConsistencyChecker::class)->warnings($bia)[0]->fingerprint;
        try {
            app(BcmConflictWorkflow::class)->accept($bia, $fingerprint, 'Review', 1, $actor);
            $this->fail('Stale lock accepted');
        } catch (ConflictHttpException) {
            $this->assertSame(0, BcmConflictAcceptance::query()->count());
        }
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
        try {
            app(BcmConflictWorkflow::class)->accept($bia, $fingerprint, 'Review', 2, $actor);
            $this->fail('Inactive actor accepted');
        } catch (AuthorizationException) {
            $this->assertSame(0, BcmConflictAcceptance::query()->count());
            $this->assertSame(2, $bia->fresh()->lock_version);
        }
    }

    private function conflict(): array
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $bia = app(BiaWorkflow::class)->draft($process, $actor);
        $bia = app(BiaWorkflow::class)->save($bia, $this->completeBiaData($configuration), 1, $actor);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $objective = app(AssetRecoveryWorkflow::class)->draft($asset, $actor);
        $objective = app(AssetRecoveryWorkflow::class)->save($objective, ['rto_minutes' => 300, 'rpo_minutes' => 0, 'target_reason' => 'Reviewed'], 1, $actor);
        app(AssetRecoveryWorkflow::class)->approve($objective, 2, $actor);
        $edge = DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id, 'importance' => 'critical']);

        return [$bia, $actor, $asset, $edge];
    }
}
