<?php

namespace Tests\Feature\Bcm;

use App\Models\Asset;
use App\Services\Bcm\AssetRecoveryWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class AssetRecoveryTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_partial_save_zero_rpo_and_historical_successor(): void
    {
        [$project, $actor] = $this->bcmProject();
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $workflow = app(AssetRecoveryWorkflow::class);
        $draft = $workflow->draft($asset, $actor);
        $partial = $workflow->save($draft, ['rto_minutes' => 30], 1, $actor);
        $saved = $workflow->save($partial, ['rpo_minutes' => 0, 'target_reason' => 'Restore immediately'], 2, $actor);
        $approved = $workflow->approve($saved, 3, $actor);
        $this->assertSame(0, $approved->rpo_minutes);
        $this->assertSame($actor->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $next = $workflow->draft($asset, $actor);
        $this->assertSame(2, $next->version);
        $workflow->save($next, ['rto_minutes' => 120], 1, $actor);
        $this->assertSame(30, $approved->fresh()->rto_minutes);
        $this->expectException(QueryException::class);
        DB::table('asset_recovery_objective_revisions')->where('id', $approved->id)->delete();
    }

    public function test_na_requires_reason_and_inactive_asset_cannot_approve(): void
    {
        [$project, $actor] = $this->bcmProject();
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $workflow = app(AssetRecoveryWorkflow::class);
        $draft = $workflow->draft($asset, $actor);
        $saved = $workflow->save($draft, ['rto_minutes' => 30, 'rpo_not_applicable' => true, 'target_reason' => 'Manual process'], 1, $actor);
        try {
            $workflow->approve($saved, 2, $actor);
            $this->fail('N/A without reason accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rpo_reason', $exception->errors());
        }
        $saved = $workflow->save($saved, ['rpo_reason' => 'No persistent data'], 2, $actor);
        DB::table('assets')->where('id', $asset->id)->update(['is_active' => false]);
        try {
            $workflow->approve($saved, 3, $actor);
            $this->fail('Inactive asset accepted');
        } catch (ValidationException) {
            $this->assertSame('draft', $saved->fresh()->status->value);
        }
        DB::table('assets')->where('id', $asset->id)->update(['is_active' => true]);
        $this->assertSame('approved', $workflow->approve($saved, 3, $actor)->status->value);
    }

    public function test_stale_generation_and_actor_cannot_change_asset_objectives(): void
    {
        [$project, $actor] = $this->bcmProject();
        $workflow = app(AssetRecoveryWorkflow::class);
        $draft = $workflow->draft(Asset::factory()->create(['project_id' => $project->id]), $actor);
        $saved = $workflow->save($draft, ['rto_minutes' => 30], 1, $actor);
        $events = DB::table('audit_events')->count();
        try {
            $workflow->save($draft, ['rto_minutes' => 120], 1, $actor);
            $this->fail('Stale generation accepted');
        } catch (ConflictHttpException) {
            $this->assertSame(30, $saved->fresh()->rto_minutes);
        }
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            $workflow->save($saved, ['rto_minutes' => 120], 2, $actor);
            $this->fail('Stale actor accepted');
        } catch (AuthorizationException) {
            $this->assertSame($events, DB::table('audit_events')->count());
        }
    }

    public function test_competing_draft_is_rejected_by_database(): void
    {
        [$project, $actor] = $this->bcmProject();
        $workflow = app(AssetRecoveryWorkflow::class);
        $draft = $workflow->draft(Asset::factory()->create(['project_id' => $project->id]), $actor);
        $this->assertSame($draft->id, $workflow->draft($draft->asset, $actor)->id);
        $data = $draft->getAttributes();
        $data['id'] = (string) Str::uuid();
        $data['version'] = 2;
        $this->expectException(QueryException::class);
        DB::table('asset_recovery_objective_revisions')->insert($data);
    }

    public function test_database_rejects_foreign_project_asset(): void
    {
        [$project, $actor] = $this->bcmProject();
        [$other] = $this->bcmProject();
        $draft = app(AssetRecoveryWorkflow::class)->draft(Asset::factory()->create(['project_id' => $project->id]), $actor);
        $foreign = Asset::factory()->create(['project_id' => $other->id]);
        $this->expectException(QueryException::class);
        DB::table('asset_recovery_objective_revisions')->where('id', $draft->id)->update(['asset_id' => $foreign->id]);
    }

    public function test_approved_asset_update_is_blocked_at_database_boundary(): void
    {
        [$project, $actor] = $this->bcmProject();
        $workflow = app(AssetRecoveryWorkflow::class);
        $draft = $workflow->draft(Asset::factory()->create(['project_id' => $project->id]), $actor);
        $approved = $workflow->approve($workflow->save($draft, ['rto_minutes' => 30, 'rpo_minutes' => 0, 'target_reason' => 'Restore'], 1, $actor), 2, $actor);
        $this->expectException(QueryException::class);
        DB::table('asset_recovery_objective_revisions')->where('id', $approved->id)->update(['rto_minutes' => 120]);
    }
}
