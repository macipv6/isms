<?php

namespace Tests\Feature\Bcm;

use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Services\Bcm\AssetRecoveryWorkflow;
use App\Services\Bcm\BcmConsistencyChecker;
use App\Services\Bcm\BcmDependencyTraversal;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class BcmConsistencyTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_supporting_shortcut_cannot_hide_critical_path_and_cycles_terminate(): void
    {
        [$project] = $this->bcmProject();
        $root = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $middle = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $direct = $this->edge($project->id, $root->id, null, $asset->id, 'supporting');
        $first = $this->edge($project->id, $root->id, $middle->id, null, 'critical');
        $second = $this->edge($project->id, $middle->id, null, $asset->id, 'critical');
        $this->edge($project->id, $middle->id, $root->id, null, 'critical');
        $inactive = Asset::factory()->create(['project_id' => $project->id, 'is_active' => false]);
        $this->edge($project->id, $root->id, null, $inactive->id, 'critical');
        $disabled = Asset::factory()->create(['project_id' => $project->id]);
        $this->edge($project->id, $root->id, null, $disabled->id, 'critical')->update(['is_active' => false]);
        $paths = app(BcmDependencyTraversal::class)->dependencies($project, $root);
        $assetPaths = array_values(array_filter($paths, fn ($path) => $path->targetId === $asset->id));
        $this->assertCount(2, $assetPaths);
        $this->assertSame([$direct->id], $assetPaths[0]->pathEdgeIds);
        $this->assertFalse($assetPaths[0]->critical);
        $this->assertSame([$first->id, $second->id], $assetPaths[1]->pathEdgeIds);
        $this->assertTrue($assetPaths[1]->critical);
        $this->assertCount(3, $paths);
    }

    public function test_rto_equal_zero_rpo_and_na_are_compared_exactly(): void
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $root = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $bia = app(BiaWorkflow::class)->draft($root, $actor);
        $bia = app(BiaWorkflow::class)->save($bia, array_replace($this->completeBiaData($configuration), ['rto_minutes' => 240]), 1, $actor);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $this->edge($project->id, $root->id, null, $asset->id, 'critical');
        $workflow = app(AssetRecoveryWorkflow::class);
        $objective = $workflow->draft($asset, $actor);
        $objective = $workflow->save($objective, ['rto_minutes' => 300, 'rpo_minutes' => 1, 'target_reason' => 'Reviewed'], 1, $actor);
        $workflow->approve($objective, 2, $actor);
        $checker = app(BcmConsistencyChecker::class);
        $warnings = $checker->warnings($bia);
        $this->assertSame(['dependency_rto_exceeds_process', 'dependency_rpo_exceeds_process'], array_column($warnings, 'code'));
        $this->assertTrue($warnings[0]->blocking);
        $fingerprint = $warnings[0]->fingerprint;
        $next = $workflow->draft($asset, $actor);
        $next = $workflow->save($next, ['rto_minutes' => 240, 'rpo_minutes' => 0], 1, $actor);
        $workflow->approve($next, 2, $actor);
        $this->assertSame([], $checker->warnings($bia));
        $snapshot = $checker->sourceSnapshot($bia);
        $next = $workflow->draft($asset, $actor);
        $next = $workflow->save($next, ['rto_minutes' => 300, 'rpo_not_applicable' => true, 'rpo_reason' => 'No stored data'], 1, $actor);
        $workflow->approve($next, 2, $actor);
        $warnings = $checker->warnings($bia);
        $this->assertSame(['dependency_rto_exceeds_process'], array_column($warnings, 'code'));
        $this->assertNotSame($fingerprint, $warnings[0]->fingerprint);
        $this->assertNotSame($snapshot, $checker->sourceSnapshot($bia));
    }

    public function test_unknown_targets_and_impact_hints_keep_provenance(): void
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $root = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $bia = app(BiaWorkflow::class)->draft($root, $actor);
        $data = $this->completeBiaData($configuration);
        $data['impacts'][0]['level'] = 4;
        $bia = app(BiaWorkflow::class)->save($bia, $data, 1, $actor);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $edge = $this->edge($project->id, $root->id, null, $asset->id, 'critical');
        $warnings = app(BcmConsistencyChecker::class)->warnings($bia);
        $this->assertSame(['dependency_target_unknown', 'mtpd_after_critical_impact', 'descending_impact'], array_column($warnings, 'code'));
        $this->assertNull($warnings[0]->revisionId);
        $this->assertSame([$edge->id], $warnings[0]->pathEdgeIds);
        $this->assertTrue($warnings[0]->blocking);
        $checker = app(BcmConsistencyChecker::class);
        $snapshot = $checker->sourceSnapshot($bia);
        $this->assertSame($snapshot, $checker->sourceSnapshot($bia));
        $bia->impacts()->where('area', 'financial')->where('minutes', 60)->update(['level' => 3]);
        $this->assertNotSame($snapshot, $checker->sourceSnapshot($bia));
    }

    public function test_foreign_root_is_rejected(): void
    {
        [$project] = $this->bcmProject();
        $foreign = BusinessProcess::factory()->create();
        $this->expectException(NotFoundHttpException::class);
        app(BcmDependencyTraversal::class)->dependencies($project, $foreign);
    }

    public function test_foreign_targets_are_rejected_by_project_foreign_keys_and_never_traversed(): void
    {
        [$project] = $this->bcmProject();
        $root = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $local = Asset::factory()->create(['project_id' => $project->id]);
        $this->edge($project->id, $root->id, null, $local->id, 'critical');
        $foreignProcess = BusinessProcess::factory()->create();
        $foreignAsset = Asset::factory()->create(['project_id' => $foreignProcess->project_id]);

        foreach (['process' => $foreignProcess, 'asset' => $foreignAsset] as $type => $foreign) {
            try {
                // The nested transaction rolls back the failed statement to a savepoint.
                // Production composite foreign keys remain enabled throughout.
                DB::transaction(fn () => $this->edge($project->id, $root->id,
                    $type === 'process' ? $foreign->id : null,
                    $type === 'asset' ? $foreign->id : null, 'critical'));
                $this->fail('Foreign target edge was persisted');
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->errorInfo[0]);
                $this->assertStringContainsString('dependency_edges_target_'.$type.'_project_foreign', $exception->getMessage());
            }
        }

        $paths = app(BcmDependencyTraversal::class)->dependencies($project, $root);
        $this->assertSame([$local->id], array_column($paths, 'targetId'));
        $this->assertDatabaseMissing('dependency_edges', ['project_id' => $project->id, 'target_process_id' => $foreignProcess->id]);
        $this->assertDatabaseMissing('dependency_edges', ['project_id' => $project->id, 'target_asset_id' => $foreignAsset->id]);
    }

    public function test_latest_approved_process_target_configuration_is_checked_and_draft_is_ignored(): void
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $root = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $target = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $workflow = app(BiaWorkflow::class);
        $rootBia = $workflow->draft($root, $actor);
        $rootBia = $workflow->save($rootBia, $this->completeBiaData($configuration), 1, $actor);
        $targetBia = $workflow->draft($target, $actor);
        $targetBia = $workflow->save($targetBia, $this->completeBiaData($configuration), 1, $actor);
        $targetBia = $workflow->approve($targetBia, 2, $actor);
        $this->edge($project->id, $root->id, $target->id, null, 'critical');
        $checker = app(BcmConsistencyChecker::class);
        $this->assertSame([], $checker->warnings($rootBia));
        $initial = $checker->sourceSnapshot($rootBia);
        $draft = $workflow->draft($target, $actor);
        $workflow->save($draft, ['rto_minutes' => 400], 1, $actor);
        $this->assertSame($initial, $checker->sourceSnapshot($rootBia));
        app(BiaConfigurationWorkflow::class)->activate($project, [60, 240, 480], 1, $actor);
        $warnings = $checker->warnings($rootBia);
        $this->assertSame(['dependency_target_stale'], array_column($warnings, 'code'));
        $this->assertSame($targetBia->id, $warnings[0]->revisionId);
        $this->assertTrue($warnings[0]->blocking);
    }

    public function test_warning_fingerprints_track_relevant_sources_and_critical_conflict_survives(): void
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $root = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $middle = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $bia = app(BiaWorkflow::class)->draft($root, $actor);
        $bia = app(BiaWorkflow::class)->save($bia, $this->completeBiaData($configuration), 1, $actor);
        $direct = $this->edge($project->id, $root->id, null, $asset->id, 'supporting');
        $this->edge($project->id, $root->id, $middle->id, null, 'critical');
        $this->edge($project->id, $middle->id, null, $asset->id, 'critical');
        $workflow = app(AssetRecoveryWorkflow::class);
        $objective = $workflow->draft($asset, $actor);
        $objective = $workflow->save($objective, ['rto_minutes' => 300, 'rpo_minutes' => 0, 'target_reason' => 'Reviewed'], 1, $actor);
        $workflow->approve($objective, 2, $actor);
        $checker = app(BcmConsistencyChecker::class);
        $assetWarnings = array_values(array_filter($checker->warnings($bia), fn ($warning) => $warning->targetId === $asset->id));
        $this->assertCount(2, $assetWarnings);
        $this->assertFalse($assetWarnings[0]->blocking);
        $this->assertTrue($assetWarnings[1]->blocking);
        $fingerprint = $assetWarnings[0]->fingerprint;
        $bia->impacts()->where('area', 'financial')->where('minutes', 60)->update(['reason' => 'New matrix explanation']);
        $assetWarnings = array_values(array_filter($checker->warnings($bia), fn ($warning) => $warning->targetId === $asset->id));
        $this->assertSame($fingerprint, $assetWarnings[0]->fingerprint);
        $direct->update(['importance' => 'critical']);
        $assetWarnings = array_values(array_filter($checker->warnings($bia), fn ($warning) => $warning->targetId === $asset->id));
        $this->assertCount(1, $assetWarnings);
        $this->assertTrue($assetWarnings[0]->blocking);
        $this->assertNotSame($fingerprint, $assetWarnings[0]->fingerprint);
    }

    public function test_one_hundred_targets_use_batched_queries(): void
    {
        [$project, $actor] = $this->bcmProject();
        app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $root = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $bia = app(BiaWorkflow::class)->draft($root, $actor);
        foreach (Asset::factory()->count(100)->create(['project_id' => $project->id]) as $asset) {
            $this->edge($project->id, $root->id, null, $asset->id, 'critical');
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $warnings = app(BcmConsistencyChecker::class)->warnings($bia);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(100, $warnings);
        $this->assertLessThan(25, $queries);
    }

    private function edge(string $project, string $source, ?string $process, ?string $asset, string $importance): DependencyEdge
    {
        return DependencyEdge::factory()->create(['project_id' => $project, 'source_process_id' => $source,
            'target_process_id' => $process, 'target_asset_id' => $asset, 'importance' => $importance]);
    }
}
