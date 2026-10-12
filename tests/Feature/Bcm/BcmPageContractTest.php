<?php

namespace Tests\Feature\Bcm;

use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\EvidenceFile;
use App\Services\Bcm\AssetRecoveryWorkflow;
use App\Services\Bcm\BcmExerciseWorkflow;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use App\Services\Bcm\ContinuityPlanWorkflow;
use App\Services\Findings\FindingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BcmPageContractTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_index_props_are_scoped_paginated_and_exclude_private_metadata_and_query_poison(): void
    {
        [$project, $actor] = $this->bcmProject();
        BusinessProcess::factory()->count(26)->create(['project_id' => $project->id]);
        BusinessProcess::factory()->create(['name' => 'Foreign secret']);
        EvidenceFile::factory()->create(['project_id' => $project->id, 'storage_path' => 'private/secret.pdf', 'sha256' => str_repeat('b', 64)]);
        $base = "/projects/{$project->id}/bcm";
        $this->actingAs($actor)->get($base.'/bia?per_page=1000&storage_path=poison')->assertOk()->assertInertia(fn (Assert $page) => $page->component('bcm/Bia', false)->where('project.id', $project->id)->where('capabilities.write', true)->where('configuration', null)->where('lock_version', 0)->has('overview')->has('sources')->has('conflicts')->has('history')->has('entries.data', 25)->where('entries.total', 26)->where('entries.per_page', 25));
        foreach (['' => 'bcm/Index', '/plans' => 'bcm/Plans', '/exercises' => 'bcm/Exercises'] as $path => $component) {
            $response = $this->get($base.$path.'?storage_path=poison');
            $response->assertOk()->assertInertia(fn (Assert $page) => $page->component($component, false)->has('project')->has('capabilities')->has('configuration')->has('overview')->has('entries')->has('sources')->has('conflicts')->has('history')->has('lock_version'));
            foreach (['private/secret.pdf', str_repeat('b', 64), 'poison', 'Foreign secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->assertDatabaseCount('bia_configuration_versions', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_bia_history_is_bounded_with_explicit_revision_ids_and_freshness(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $bia = $plan->processes()->sole()->bia;
        $draft = app(BiaWorkflow::class)->draft($bia->analysis->process, $actor);
        $base = "/projects/{$project->id}/bcm";
        $count = DB::table('audit_events')->count();
        $this->actingAs($actor)->get($base.'/bia/'.$bia->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('bcm/Bia', false)->where('revision.id', $bia->id)->where('revision.status', 'approved')->where('revision.freshness.current', true)->where('capabilities.edit', false)->where('capabilities.revise', true)->where('history.data.0.id', $draft->id)->where('history.data.1.id', $bia->id)->where('history.per_page', 25)->where('lock_version', 3)->missing('revision.source_snapshot'));
        app(BiaConfigurationWorkflow::class)->activate($project, [60, 240], 1, $actor);
        $this->get($base.'/bia/'.$bia->id)->assertInertia(fn (Assert $page) => $page->where('revision.freshness.current', false)->where('revision.status', 'approved'));
        $this->assertSame($count + 1, DB::table('audit_events')->count());
        $this->assertSame($draft->bia_configuration_version_id, $draft->fresh()->bia_configuration_version_id);
    }

    public function test_plan_and_exercise_details_expose_selected_children_and_finding_freeze_capability(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($plan, ['scenario' => 'Outage', 'exercise_type' => 'tabletop', 'planned_at' => '2026-11-01', 'leader_name' => 'Lead', 'participants' => 'Team', 'criteria' => [['expected_result' => 'Restore']]], $actor);
        $exercise = $w->plan($exercise, 1, $actor);
        $exercise = $w->record($exercise, ['results' => [['criterion_id' => $exercise->criteria()->sole()->id, 'outcome' => 'failed', 'observations' => 'Too slow']]], 2, $actor);
        $result = $exercise->results()->sole();
        $finding = app(FindingWorkflow::class)->proposeFromExercise($result, ['title' => 'Correction', 'description' => 'Restore faster', 'severity' => 'high'], $actor);
        $base = "/projects/{$project->id}/bcm";
        $count = DB::table('audit_events')->count();
        $this->actingAs($actor)->get($base.'/plans/'.$plan->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('bcm/Plans', false)->where('revision.id', $plan->id)->where('revision.freshness.current', true)->has('revision.steps', 1)->has('revision.roles', 1)->has('sources.data', 1)->missing('revision.source_snapshot')->where('capabilities.edit', false));
        $this->get($base.'/exercises/'.$exercise->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('bcm/Exercises', false)->where('exercise.id', $exercise->id)->where('exercise.plan_revision_id', $plan->id)->where('results.data.0.id', $result->id)->where('results.data.0.capabilities.edit', false)->where('results.data.0.capabilities.propose_finding', true)->where('results.data.0.capabilities.waive_finding', false)->where('results.data.0.findings.data.0.id', $finding->id)->where('results.per_page', 25)->missing('exercise.source_snapshot'));
        $this->assertSame($count, DB::table('audit_events')->count());
        $revision = app(ContinuityPlanWorkflow::class)->revise($plan, $actor);
        $this->get($base.'/plans/'.$revision->id)->assertInertia(fn (Assert $page) => $page->where('capabilities.edit', true)->where('history.data.1.id', $plan->id));
    }

    public function test_history_and_results_pagination_do_not_disclose_unbounded_lists(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $workflow = app(ContinuityPlanWorkflow::class);
        for ($version = 2; $version <= 26; $version++) {
            $plan = $workflow->approve($workflow->revise($plan, $actor), 1, $actor);
        }
        $base = "/projects/{$project->id}/bcm";
        $this->actingAs($actor)->get($base.'/plans/'.$plan->id)->assertInertia(fn (Assert $page) => $page->has('history.data', 25)->where('history.total', 26)->where('history.data.0.version', 26));
        $this->get($base.'/plans/'.$plan->id.'?history_page=2')->assertInertia(fn (Assert $page) => $page->has('history.data', 1)->where('history.data.0.version', 1));
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($plan, ['scenario' => 'Outage', 'exercise_type' => 'tabletop', 'planned_at' => '2026-11-01', 'leader_name' => 'Lead', 'participants' => 'Team', 'criteria' => array_fill(0, 26, ['expected_result' => 'Restore'])], $actor);
        $exercise = $w->plan($exercise, 1, $actor);
        $exercise = $w->record($exercise, ['results' => $exercise->criteria()->get()->map(fn ($criterion) => ['criterion_id' => $criterion->id, 'outcome' => 'passed'])->all()], 2, $actor);
        $this->get($base.'/exercises/'.$exercise->id)->assertInertia(fn (Assert $page) => $page->has('results.data', 25)->where('results.total', 26));
        $this->get($base.'/exercises/'.$exercise->id.'?results_page=2')->assertInertia(fn (Assert $page) => $page->has('results.data', 1));
        $this->get($base)->assertInertia(fn (Assert $page) => $page->where('overview.bia_review_required', 0));
        app(BiaConfigurationWorkflow::class)->activate($project, [60, 240], 1, $actor);
        $this->get($base)->assertInertia(fn (Assert $page) => $page->where('overview.bia_review_required', 1)->where('overview.plan_review_required', 1));
    }

    public function test_approved_bia_sources_keep_used_revision_when_current_asset_head_advances(): void
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $aw = app(AssetRecoveryWorkflow::class);
        $original = $aw->approve($aw->save($aw->draft($asset, $actor), ['rto_minutes' => 60, 'rpo_minutes' => 0, 'target_reason' => 'Reviewed'], 1, $actor), 2, $actor);
        DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'source_asset_id' => null, 'target_process_id' => null, 'target_asset_id' => $asset->id, 'importance' => 'critical']);
        $bw = app(BiaWorkflow::class);
        $bia = $bw->approve($bw->save($bw->draft($process, $actor), $this->completeBiaData($configuration), 1, $actor), 2, $actor);
        $successor = $aw->approve($aw->save($aw->draft($asset, $actor), ['rto_minutes' => 100], 1, $actor), 2, $actor);
        $this->assertNotSame($original->id, $successor->id);
        $this->actingAs($actor)->get("/projects/{$project->id}/bcm/bia/{$bia->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('sources.data.0.revision_id', $original->id)->where('sources.data.0.version', 1)->where('revision.freshness.current', false)->where('revision.status', 'approved'));
    }
}
