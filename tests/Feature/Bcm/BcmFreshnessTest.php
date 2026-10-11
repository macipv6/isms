<?php

namespace Tests\Feature\Bcm;

use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Services\Bcm\AssetRecoveryWorkflow;
use App\Services\Bcm\BcmConsistencyChecker;
use App\Services\Bcm\BcmFreshness;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BcmFreshnessTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_warning_free_sources_and_config_changes_require_review_without_changing_history(): void
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $assetWorkflow = app(AssetRecoveryWorkflow::class);
        $objective = $assetWorkflow->draft($asset, $actor);
        $objective = $assetWorkflow->save($objective, ['rto_minutes' => 60, 'rpo_minutes' => 0, 'target_reason' => 'Reviewed'], 1, $actor);
        $assetWorkflow->approve($objective, 2, $actor);
        $edge = DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id, 'importance' => 'critical']);
        $workflow = app(BiaWorkflow::class);
        $bia = $workflow->draft($process, $actor);
        $bia = $workflow->save($bia, $this->completeBiaData($configuration), 1, $actor);
        $old = $workflow->approve($bia, 2, $actor);
        $snapshot = $old->source_snapshot;
        $freshness = app(BcmFreshness::class);
        $this->assertTrue($freshness->bia($old)['current']);
        $this->assertSame([], $snapshot['warning_fingerprints']);
        $assetWorkflow->approve($assetWorkflow->draft($asset, $actor), 1, $actor);
        $this->assertFalse($freshness->bia($old)['current']);
        $this->assertContains('dependency_sources_changed', $freshness->bia($old)['reasons']);
        $edge->update(['is_active' => false]);
        $this->assertFalse($freshness->bia($old)['current']);
        app(BiaConfigurationWorkflow::class)->activate($project, [60, 240, 480], 1, $actor);
        $this->assertContains('configuration_changed', $freshness->bia($old)['reasons']);
        $process->update(['is_active' => false]);
        $this->assertContains('root_inactive', $freshness->bia($old)['reasons']);
        $this->assertSame('approved', $old->fresh()->status->value);
        $this->assertEquals($snapshot, $old->fresh()->source_snapshot);
    }

    public function test_process_dependency_checks_full_pinned_sources_and_terminates_cycles(): void
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $root = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $target = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $workflow = app(BiaWorkflow::class);
        $targetBia = $workflow->draft($target, $actor);
        $targetBia = $workflow->save($targetBia, $this->completeBiaData($configuration), 1, $actor);
        $targetBia = $workflow->approve($targetBia, 2, $actor);
        DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $root->id, 'target_process_id' => $target->id, 'target_asset_id' => null, 'importance' => 'critical']);
        $bia = $workflow->draft($root, $actor);
        $bia = $workflow->save($bia, $this->completeBiaData($configuration), 1, $actor);
        $old = $workflow->approve($bia, 2, $actor);
        $freshness = app(BcmFreshness::class);
        $this->assertTrue($freshness->bia($old)['current']);
        DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $target->id, 'target_process_id' => $root->id, 'target_asset_id' => null, 'importance' => 'critical']);
        $this->assertFalse($freshness->bia($targetBia)['current']);
        $this->assertFalse($freshness->bia($old)['current']);
        $warnings = app(BcmConsistencyChecker::class)->warnings($old);
        $this->assertContains('freshness_cycle', $freshness->bia($old)['reasons']);
        $this->assertSame(['dependency_target_stale'], array_column($warnings, 'code'));
        $this->assertTrue($warnings[0]->blocking);
    }
}
