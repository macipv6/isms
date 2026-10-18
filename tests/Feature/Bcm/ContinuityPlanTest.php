<?php

namespace Tests\Feature\Bcm;

use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\EvidenceFile;
use App\Models\RiskAnalysis;
use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;
use App\Services\Bcm\AssetRecoveryWorkflow;
use App\Services\Bcm\BcmFreshness;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use App\Services\Bcm\ContinuityPlanWorkflow;
use App\Services\Projects\ProjectWorkflow;
use App\Services\Threats\ProjectRiskContextService;
use Database\Seeders\RiskMethodologySeeder;
use Database\Seeders\ThreatCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ContinuityPlanTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_release_pins_server_sources_and_successor_preserves_history(): void
    {
        [$project, $actor] = $this->bcmProject();
        $process = $this->planProcess($project, $actor);
        $evidence = EvidenceFile::factory()->create(['project_id' => $project->id, 'status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $risk = $this->planRisk($project, $actor, $process);
        $workflow = app(ContinuityPlanWorkflow::class);
        $revision = $workflow->create($project, [...$this->planData($process), 'evidence_ids' => [$evidence->id], 'risk_analysis_ids' => [$risk->id], 'source_snapshot' => ['spoof' => true]], $actor);
        $released = $workflow->approve($revision, 1, $actor);
        $this->assertTrue(app(BcmFreshness::class)->plan($released)['current']);
        $this->assertSame($process->id, $released->source_snapshot['processes'][0]['process_id']);
        $this->assertArrayNotHasKey('spoof', $released->source_snapshot);
        $this->assertSame([$risk->id], $released->source_snapshot['risk_analysis_ids']);
        $draft = $workflow->revise($released, $actor);
        $this->assertSame($draft->id, $workflow->revise($released, $actor)->id);
        $this->assertSame(2, $draft->version);
        $workflow->save($draft, ['steps' => [], 'roles' => [], 'process_ids' => [], 'evidence_ids' => [], 'risk_analysis_ids' => []], 1, $actor);
        $this->assertSame(1, $released->steps()->count());
        $this->assertSame(1, $released->roles()->count());
        $this->assertSame(1, $released->processes()->count());
        $this->assertSame(1, $released->evidenceLinks()->count());
        $this->assertSame(1, $released->riskLinks()->count());
        $audit = DB::table('audit_events')->where('event_type', 'bcm.plan.approved')->first();
        $this->assertStringNotContainsString('Leader Secret', json_encode($audit));
    }

    public function test_successor_can_replace_a_deactivated_historical_process(): void
    {
        [$project, $actor] = $this->bcmProject();
        $oldProcess = $this->planProcess($project, $actor);
        $replacement = $this->planProcess($project, $actor);
        $workflow = app(ContinuityPlanWorkflow::class);
        $released = $workflow->approve($workflow->create($project, $this->planData($oldProcess), $actor), 1, $actor);
        $oldProcess->update(['is_active' => false]);

        $draft = $workflow->revise($released, $actor);
        $this->assertSame($draft->id, $workflow->revise($released, $actor)->id);
        try {
            $workflow->approve($draft, 1, $actor);
            $this->fail('Inactive historical process must still block approval.');
        } catch (ValidationException) {
            $this->assertSame('draft', $draft->fresh()->status->value);
        }
        $draft = $workflow->save($draft, ['process_ids' => [$replacement->id]], 1, $actor);
        $approved = $workflow->approve($draft, $draft->lock_version, $actor);
        $this->assertSame('approved', $approved->status->value);
        $this->assertSame($replacement->id, $approved->processes()->sole()->business_process_id);
        $this->assertSame($oldProcess->id, $released->processes()->sole()->business_process_id);
    }

    #[DataProvider('missingFields')]
    public function test_missing_release_data_is_rejected(string $field, mixed $value): void
    {
        [$project, $actor] = $this->bcmProject();
        $data = $this->planData($this->planProcess($project, $actor));
        $data[$field] = $value;
        $revision = app(ContinuityPlanWorkflow::class)->create($project, $data, $actor);
        $this->expectException(ValidationException::class);
        app(ContinuityPlanWorkflow::class)->approve($revision, 1, $actor);
    }

    public static function missingFields(): array
    {
        $cases = [];
        foreach (['title', 'scenario', 'activation_conditions', 'strategy', 'minimum_operation', 'normal_operation_return'] as $field) {
            $cases[$field] = [$field, ' '];
        }
        $cases['process'] = ['process_ids', []];
        $cases['step'] = ['steps', []];
        $cases['step owner'] = ['steps', [['instruction' => 'Restore', 'owner_name' => ' ']]];
        $cases['step instruction'] = ['steps', [['instruction' => ' ', 'owner_name' => 'Owner']]];
        $cases['role'] = ['roles', []];
        $cases['responsible'] = ['roles', [['role' => 'Lead', 'name' => ' ', 'deputy_name' => 'Backup']]];
        $cases['deputy'] = ['roles', [['role' => 'Lead', 'name' => 'Owner', 'deputy_name' => ' ']]];

        return $cases;
    }

    #[DataProvider('badEvidenceStatuses')]
    public function test_evidence_is_checked_fresh_on_release(string $status): void
    {
        [$project, $actor] = $this->bcmProject();
        $evidence = EvidenceFile::factory()->create(['project_id' => $project->id, 'status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $revision = app(ContinuityPlanWorkflow::class)->create($project, [...$this->planData($this->planProcess($project, $actor)), 'evidence_ids' => [$evidence->id]], $actor);
        $evidence->update(['status' => $status]);
        $this->expectException(ValidationException::class);
        app(ContinuityPlanWorkflow::class)->approve($revision, 1, $actor);
    }

    public static function badEvidenceStatuses(): array
    {
        return [['pending_review'], ['rejected']];
    }

    #[DataProvider('foreignKinds')]
    public function test_foreign_references_are_scoped(string $key, string $model): void
    {
        [$project, $actor] = $this->bcmProject();
        if ($model === RiskAnalysis::class) {
            [$foreignProject, $foreignActor] = $this->bcmProject();
            $foreign = $this->planRisk($foreignProject, $foreignActor, BusinessProcess::factory()->create(['project_id' => $foreignProject->id]));
        } else {
            $foreign = $model::factory()->create();
        }
        $this->expectException(ModelNotFoundException::class);
        app(ContinuityPlanWorkflow::class)->create($project, [$key => [$foreign->id]], $actor);
    }

    public static function foreignKinds(): array
    {
        return [['process_ids', BusinessProcess::class], ['evidence_ids', EvidenceFile::class], ['risk_analysis_ids', RiskAnalysis::class]];
    }

    public function test_inactive_and_stale_process_sources_block_release_and_mark_history(): void
    {
        [$project, $actor] = $this->bcmProject();
        $process = $this->planProcess($project, $actor);
        $workflow = app(ContinuityPlanWorkflow::class);
        $revision = $workflow->create($project, $this->planData($process), $actor);
        app(BiaConfigurationWorkflow::class)->activate($project, [60, 240], 1, $actor);
        try {
            $workflow->approve($revision, 1, $actor);
            $this->fail('Stale BIA was approved');
        } catch (ValidationException) {
            $this->assertSame('draft', $revision->fresh()->status->value);
        }
        $process->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $workflow->save($revision, ['title' => 'New'], 1, $actor);
    }

    public function test_full_source_freshness_checks_asset_heads_graph_and_revoked_evidence_without_caching(): void
    {
        [$project, $actor] = $this->bcmProject();
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $aw = app(AssetRecoveryWorkflow::class);
        $objective = $aw->save($aw->draft($asset, $actor), ['rto_minutes' => 60, 'rpo_minutes' => 0, 'target_reason' => 'Reviewed'], 1, $actor);
        $objective = $aw->approve($objective, 2, $actor);
        DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id]);
        $this->planProcess($project, $actor, $process);
        $evidence = EvidenceFile::factory()->create(['project_id' => $project->id, 'status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $w = app(ContinuityPlanWorkflow::class);
        $released = $w->approve($w->create($project, [...$this->planData($process), 'evidence_ids' => [$evidence->id]], $actor), 1, $actor);
        $freshness = app(BcmFreshness::class);
        $this->assertTrue($freshness->plan($released)['current']);
        $this->assertSame($objective->id, $released->source_snapshot['assets'][0]['revision_id']);
        $aw->approve($aw->draft($asset, $actor), 1, $actor);
        $this->assertContains('asset_sources_changed', $freshness->plan($released)['reasons']);
        $evidence->update(['status' => 'rejected']);
        $this->assertContains('evidence_unverified', $freshness->plan($released)['reasons']);
        $this->assertSame('approved', $released->fresh()->status->value);
    }

    #[DataProvider('childRelations')]
    public function test_approved_children_cannot_be_detached_or_changed(string $relation): void
    {
        [$project, $actor] = $this->bcmProject();
        $proof = EvidenceFile::factory()->create(['project_id' => $project->id, 'status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $process = $this->planProcess($project, $actor);
        $risk = $this->planRisk($project, $actor, $process);
        $w = app(ContinuityPlanWorkflow::class);
        $r = $w->approve($w->create($project, [...$this->planData($process), 'evidence_ids' => [$proof->id], 'risk_analysis_ids' => [$risk->id]], $actor), 1, $actor);
        $this->expectException(QueryException::class);
        $r->{$relation}()->delete();
    }

    public static function childRelations(): array
    {
        return [['steps'], ['roles'], ['processes'], ['evidenceLinks'], ['riskLinks']];
    }

    public function test_stale_locks_and_actor_are_rechecked_and_project_completion_is_unchanged(): void
    {
        [$project, $actor] = $this->bcmProject();
        $w = app(ContinuityPlanWorkflow::class);
        $r = $w->create($project, [], $actor);
        try {
            $w->save($r, ['title' => 'No'], 99, $actor);
            $this->fail('Stale lock accepted');
        } catch (ConflictHttpException) {
            $this->assertSame(1, $r->fresh()->lock_version);
        }
        $actor->update(['is_active' => false]);
        try {
            $w->save($r, [], 1, $actor);
            $this->fail('Inactive actor accepted');
        } catch (AuthorizationException) {
            $this->assertSame(1, $r->fresh()->lock_version);
        }
        $actor->update(['is_active' => true, 'role' => 'admin']);
        BusinessProcess::factory()->create(['project_id' => $project->id]);
        $this->assertSame('completed', app(ProjectWorkflow::class)->update($project, ['status' => 'completed'], $actor)->status->value);
    }

    public function test_multiple_processes_pin_latest_heads_and_identical_save_does_not_advance_lock(): void
    {
        [$project, $actor] = $this->bcmProject();
        $first = $this->planProcess($project, $actor);
        $second = $this->planProcess($project, $actor);
        $data = [...$this->planData($first), 'process_ids' => [$first->id, $second->id]];
        $w = app(ContinuityPlanWorkflow::class);
        $draft = $w->create($project, $data, $actor);
        $this->assertSame(1, $w->save($draft, $data, 1, $actor)->lock_version);
        $released = $w->approve($draft, 1, $actor);
        $this->assertCount(2, $released->source_snapshot['processes']);
        $bia = app(BiaWorkflow::class);
        $successor = $bia->approve($bia->draft($second, $actor), 1, $actor);
        $this->assertContains('bia_sources_changed', app(BcmFreshness::class)->plan($released)['reasons']);
        $draft = $w->revise($released, $actor);
        $new = $w->approve($draft, 1, $actor);
        $this->assertContains($successor->id, array_column($new->source_snapshot['processes'], 'revision_id'));
        $this->assertContains('newer_revision', app(BcmFreshness::class)->plan($released)['reasons']);
    }

    #[DataProvider('invalidDurations')]
    public function test_duration_requires_native_nonnegative_integer(mixed $minutes): void
    {
        [$project, $actor] = $this->bcmProject();
        $this->expectException(ValidationException::class);
        app(ContinuityPlanWorkflow::class)->create($project, ['steps' => [['expected_minutes' => $minutes]]], $actor);
    }

    public static function invalidDurations(): array
    {
        return [[''], ['1'], [-1], [0.5], [true]];
    }

    #[DataProvider('invalidRows')]
    public function test_malformed_rows_fail_validation_instead_of_type_errors(string $key, mixed $row): void
    {
        [$project, $actor] = $this->bcmProject();
        $this->expectException(ValidationException::class);
        app(ContinuityPlanWorkflow::class)->create($project, [$key => [$row]], $actor);
    }

    public static function invalidRows(): array
    {
        return [['steps', ''], ['roles', ''], ['steps', null], ['roles', null]];
    }

    public function test_missing_approved_bia_blocks_release(): void
    {
        [$project, $actor] = $this->bcmProject();
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $w = app(ContinuityPlanWorkflow::class);
        $draft = $w->create($project, $this->planData($process), $actor);
        $this->expectException(ValidationException::class);
        $w->approve($draft, 1, $actor);
    }

    public function test_inactive_process_cannot_be_added(): void
    {
        [$project, $actor] = $this->bcmProject();
        $process = BusinessProcess::factory()->create(['project_id' => $project->id, 'is_active' => false]);
        $this->expectException(ValidationException::class);
        app(ContinuityPlanWorkflow::class)->create($project, ['process_ids' => [$process->id]], $actor);
    }

    public function test_database_rejects_foreign_project_child(): void
    {
        [$project, $actor] = $this->bcmProject();
        $draft = app(ContinuityPlanWorkflow::class)->create($project, [], $actor);
        $foreign = BusinessProcess::factory()->create();
        $this->expectException(QueryException::class);
        $draft->processes()->create(['project_id' => $project->id, 'business_process_id' => $foreign->id]);
    }

    #[DataProvider('historyMutations')]
    public function test_database_blocks_all_approved_history_mutations(string $mutation): void
    {
        [$project, $actor] = $this->bcmProject();
        $released = $this->approvedPlan($project, $actor);
        $this->expectException(QueryException::class);
        match ($mutation) {
            'revision' => $released->update(['title' => 'Changed']),
            'update' => $released->steps()->update(['instruction' => 'Changed']),
            'insert' => $released->roles()->create(['project_id' => $project->id, 'position' => 2]),
        };
    }

    public static function historyMutations(): array
    {
        return [['revision'], ['update'], ['insert']];
    }

    private function planProcess($project, $actor, ?BusinessProcess $process = null): BusinessProcess
    {
        $process ??= BusinessProcess::factory()->create(['project_id' => $project->id]);
        $config = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $w = app(BiaWorkflow::class);
        $w->approve($w->save($w->draft($process, $actor), $this->completeBiaData($config), 1, $actor), 2, $actor);

        return $process;
    }

    private function planData(BusinessProcess $process): array
    {
        return ['title' => 'Plan', 'scenario' => 'Outage', 'activation_conditions' => 'Service unavailable', 'strategy' => 'Restore backup', 'minimum_operation' => 'Manual service', 'normal_operation_return' => 'Validate service', 'process_ids' => [$process->id], 'steps' => [['instruction' => 'Restore backup', 'owner_name' => 'Operator', 'expected_minutes' => 0]], 'roles' => [['role' => 'Lead', 'name' => 'Leader Secret', 'deputy_name' => 'Deputy', 'email' => 'lead@example.com', 'phone' => '+123']]];
    }

    private function planRisk($project, $actor, BusinessProcess $process): RiskAnalysis
    {
        $this->seed([RiskMethodologySeeder::class, ThreatCatalogSeeder::class]);
        $context = app(ProjectRiskContextService::class)->initialize($project, RiskMethodologyVersion::query()->sole(), ThreatCatalogVersion::query()->sole(), $actor);

        return RiskAnalysis::factory()->create(['project_id' => $project->id, 'business_process_id' => $process->id, 'project_threat_id' => $context->projectThreats()->firstOrFail()->id]);
    }
}
