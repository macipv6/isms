<?php

namespace Tests\Feature\Bcm;

use App\Models\BusinessProcess;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class BiaWorkflowTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_partial_draft_then_complete_selfapproval_and_exact_time_copy(): void
    {
        [$project, $actor] = $this->bcmProject();
        $config = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $workflow = app(BiaWorkflow::class);
        $draft = $workflow->draft($process, $actor);
        $this->assertSame($draft->id, $workflow->draft($process, $actor)->id);
        $partial = $workflow->save($draft, ['owner_name' => 'Owner'], 1, $actor);
        $this->assertSame(2, $partial->lock_version);
        $this->assertNull($partial->rto_minutes);
        $saved = $workflow->save($partial, $this->completeBiaData($config), 2, $actor);
        $approved = $workflow->approve($saved, 3, $actor);
        $this->assertSame('approved', $approved->status->value);
        $this->assertSame(0, $approved->rpo_minutes);
        $this->assertSame($actor->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertInstanceOf(CarbonImmutable::class, $approved->fresh()->created_at);
        $this->assertSame(24, $approved->impacts()->count());
        app(BiaConfigurationWorkflow::class)->activate($project, [60, 120, 240], 1, $actor);
        $next = $workflow->draft($process, $actor);
        $this->assertSame(2, $next->version);
        $this->assertSame(8, $next->impacts()->count());
        $this->assertSame([60, 240], $next->impacts()->distinct()->orderBy('minutes')->pluck('minutes')->all());
        $this->assertSame(24, $approved->fresh()->impacts()->count());
        $this->assertSame($config->id, $approved->fresh()->bia_configuration_version_id);
    }

    #[DataProvider('invalidReleases')]
    public function test_release_rejects_incomplete_or_invalid_own_fields(string $change): void
    {
        [$project, $actor] = $this->bcmProject();
        $config = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $workflow = app(BiaWorkflow::class);
        $draft = $workflow->draft(BusinessProcess::factory()->create(['project_id' => $project->id]), $actor);
        $data = $this->completeBiaData($config);
        match ($change) {
            'matrix' => array_pop($data['impacts']),
            'reason' => $data['impacts'][0]['reason'] = '',
            'owner' => $data['owner_name'] = '',
            'target_reason' => $data['target_reason'] = '',
            'equal' => $data['rto_minutes'] = $data['mtpd_minutes'],
            'na_reason' => [$data['rpo_minutes'], $data['rpo_not_applicable'], $data['rpo_reason']] = [null, true, ''],
        };
        $saved = $workflow->save($draft, $data, 1, $actor);
        $events = DB::table('audit_events')->count();
        try {
            $workflow->approve($saved, 2, $actor);
            $this->fail('Invalid release accepted');
        } catch (ValidationException) {
            $this->assertSame('draft', $saved->fresh()->status->value);
            $this->assertSame($events, DB::table('audit_events')->count());
        }
    }

    public static function invalidReleases(): array
    {
        return [['matrix'], ['reason'], ['owner'], ['target_reason'], ['equal'], ['na_reason']];
    }

    public function test_obsolete_draft_requires_explicit_rebase_and_cannot_approve_new_empty_slots(): void
    {
        [$project, $actor] = $this->bcmProject();
        $config = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $workflow = app(BiaWorkflow::class);
        $draft = $workflow->save($workflow->draft($process, $actor), $this->completeBiaData($config), 1, $actor);
        $new = app(BiaConfigurationWorkflow::class)->activate($project, [60, 120, 240], 1, $actor);
        try {
            $workflow->approve($draft, 2, $actor);
            $this->fail('Obsolete configuration approved');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bia_configuration_version_id', $exception->errors());
        }
        $rebased = $workflow->draft($process, $actor);
        $this->assertSame($draft->id, $rebased->id);
        $this->assertSame($new->id, $rebased->bia_configuration_version_id);
        $this->assertSame(3, $rebased->lock_version);
        $this->assertSame(8, $rebased->impacts()->count());
        $events = DB::table('audit_events')->count();
        $this->assertSame(3, $workflow->draft($process, $actor)->lock_version);
        $this->assertSame($events, DB::table('audit_events')->count());
        try {
            $workflow->save($draft, ['owner_name' => 'Stale'], 2, $actor);
            $this->fail('Pre-rebase generation accepted');
        } catch (ConflictHttpException) {
            $this->assertSame($events, DB::table('audit_events')->count());
        }
        $this->expectException(ValidationException::class);
        $workflow->approve($rebased, 3, $actor);
    }

    public function test_unchanged_payload_does_not_advance_generation_or_audit(): void
    {
        [$project, $actor] = $this->bcmProject();
        $config = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $workflow = app(BiaWorkflow::class);
        $draft = $workflow->draft(BusinessProcess::factory()->create(['project_id' => $project->id]), $actor);
        $saved = $workflow->save($draft, $this->completeBiaData($config), 1, $actor);
        $events = DB::table('audit_events')->count();
        $this->assertSame(2, $workflow->save($saved, $this->completeBiaData($config), 2, $actor)->lock_version);
        $this->assertSame($events, DB::table('audit_events')->count());
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_fails_atomically(array $data): void
    {
        [$project, $actor] = $this->bcmProject();
        app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $workflow = app(BiaWorkflow::class);
        $draft = $workflow->draft(BusinessProcess::factory()->create(['project_id' => $project->id]), $actor);
        $events = DB::table('audit_events')->count();
        try {
            $workflow->save($draft, $data, 1, $actor);
            $this->fail('Invalid payload accepted');
        } catch (ValidationException) {
            $this->assertSame(1, $draft->fresh()->lock_version);
            $this->assertSame($events, DB::table('audit_events')->count());
        }
    }

    public static function invalidPayloads(): array
    {
        return [[['rto_minutes' => '30']], [['rto_minutes' => '']], [['rpo_minutes' => -1]], [['rpo_not_applicable' => 'true']], [['rpo_not_applicable' => '']],
            [['impacts' => [['area' => 'financial', 'minutes' => 60, 'level' => '']]]],
            [['impacts' => [['area' => 'financial', 'minutes' => 120, 'level' => 2]]]],
            [['impacts' => [['area' => 'other', 'minutes' => 60, 'level' => 2]]]],
            [['impacts' => [['area' => 'financial', 'minutes' => 60, 'level' => 2], ['area' => 'financial', 'minutes' => 60, 'level' => 3]]]]];
    }

    public function test_stale_version_and_stale_actor_and_inactive_process_do_not_write(): void
    {
        [$project, $actor] = $this->bcmProject();
        app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $workflow = app(BiaWorkflow::class);
        $draft = $workflow->draft($process, $actor);
        $workflow->save($draft, ['owner_name' => 'Owner'], 1, $actor);
        $events = DB::table('audit_events')->count();
        try {
            $workflow->save($draft, ['owner_name' => 'Stale'], 1, $actor);
            $this->fail('Stale version accepted');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            $workflow->save($draft, ['owner_name' => 'Forbidden'], 2, $actor);
            $this->fail('Stale actor accepted');
        } catch (AuthorizationException) {
            $this->assertSame($events, DB::table('audit_events')->count());
        }
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        DB::table('business_processes')->where('id', $process->id)->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $workflow->approve($draft, 2, $actor);
    }

    #[DataProvider('immutableOperations')]
    public function test_database_protects_history_and_competing_drafts(string $operation): void
    {
        [$project, $actor] = $this->bcmProject();
        $config = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $workflow = app(BiaWorkflow::class);
        $draft = $workflow->draft(BusinessProcess::factory()->create(['project_id' => $project->id]), $actor);
        if ($operation === 'duplicate_draft') {
            $attributes = $draft->getAttributes();
            $attributes['id'] = (string) Str::uuid();
            $attributes['version'] = 2;
            $this->expectException(QueryException::class);
            DB::table('bia_revisions')->insert($attributes);

            return;
        }
        $approved = $workflow->approve($workflow->save($draft, $this->completeBiaData($config), 1, $actor), 2, $actor);
        $this->expectException(QueryException::class);
        match ($operation) {
            'revision_update' => DB::table('bia_revisions')->where('id', $approved->id)->update(['owner_name' => 'Changed']),
            'revision_delete' => DB::table('bia_revisions')->where('id', $approved->id)->delete(),
            'impact_update' => DB::table('bia_impacts')->where('bia_revision_id', $approved->id)->update(['level' => 4]),
            'impact_delete' => DB::table('bia_impacts')->where('bia_revision_id', $approved->id)->delete(),
        };
    }

    public static function immutableOperations(): array
    {
        return [['revision_update'], ['revision_delete'], ['impact_update'], ['impact_delete'], ['duplicate_draft']];
    }

    #[DataProvider('foreignReferences')]
    public function test_database_rejects_foreign_project_references_and_identity_reassignment(string $target): void
    {
        [$project, $actor] = $this->bcmProject();
        [$other] = $this->bcmProject();
        app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $foreignConfig = app(BiaConfigurationWorkflow::class)->initialize($other, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $otherProcess = BusinessProcess::factory()->create(['project_id' => $other->id]);
        $draft = app(BiaWorkflow::class)->draft($process, $actor);
        $this->expectException(QueryException::class);
        match ($target) {
            'configuration' => DB::table('bia_revisions')->where('id', $draft->id)->update(['bia_configuration_version_id' => $foreignConfig->id]),
            'identity' => DB::table('business_impact_analyses')->where('id', $draft->business_impact_analysis_id)->update(['business_process_id' => $otherProcess->id]),
            'impact' => DB::table('bia_impacts')->insert(['id' => (string) Str::uuid(), 'project_id' => $other->id, 'bia_revision_id' => $draft->id, 'area' => 'financial', 'minutes' => 60]),
        };
    }

    public static function foreignReferences(): array
    {
        return [['configuration'], ['identity'], ['impact']];
    }

    public function test_drafting_does_not_initialize_configuration_as_a_side_effect(): void
    {
        [$project, $actor] = $this->bcmProject();
        try {
            app(BiaWorkflow::class)->draft(BusinessProcess::factory()->create(['project_id' => $project->id]), $actor);
            $this->fail('Missing configuration accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('bia_configuration_versions', 0);
            $this->assertDatabaseCount('business_impact_analyses', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }
}
