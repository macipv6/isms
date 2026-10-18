<?php

namespace Tests\Feature\Bcm;

use App\Enums\FindingStatus;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\EvidenceFile;
use App\Models\RiskAnalysis;
use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;
use App\Services\Bcm\AssetRecoveryWorkflow;
use App\Services\Bcm\BcmExerciseWorkflow;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use App\Services\Bcm\ContinuityPlanWorkflow;
use App\Services\Findings\FindingWorkflow;
use App\Services\Measures\MeasureWorkflow;
use App\Services\Threats\ProjectRiskContextService;
use Database\Seeders\RiskMethodologySeeder;
use Database\Seeders\ThreatCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BcmPageContractTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_plan_exercise_picker_props_are_scoped_bounded_and_read_only(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        BusinessProcess::factory()->count(26)->create(['project_id' => $project->id]);
        $inactive = BusinessProcess::factory()->create(['project_id' => $project->id, 'is_active' => false]);
        $foreign = BusinessProcess::factory()->create(['name' => 'Foreign picker secret']);
        $this->seed([RiskMethodologySeeder::class, ThreatCatalogSeeder::class]);
        $context = app(ProjectRiskContextService::class)->initialize($project, RiskMethodologyVersion::query()->sole(), ThreatCatalogVersion::query()->sole(), $actor);
        RiskAnalysis::factory()->count(26)->create(['project_id' => $project->id, 'business_process_id' => $plan->processes()->sole()->business_process_id, 'project_threat_id' => $context->projectThreats()->firstOrFail()->id]);
        $foreignContext = app(ProjectRiskContextService::class)->initialize($foreign->project, RiskMethodologyVersion::query()->sole(), ThreatCatalogVersion::query()->sole(), $actor);
        $foreignRisk = RiskAnalysis::factory()->create(['project_id' => $foreign->project_id, 'business_process_id' => $foreign->id, 'project_threat_id' => $foreignContext->projectThreats()->firstOrFail()->id]);
        $foreignPlan = $this->approvedPlan(...$this->bcmProject());
        $count = DB::table('audit_events')->count();
        $base = "/projects/{$project->id}/bcm";
        $response = $this->actingAs($actor)->get($base.'/plans?process_options_page=2&risk_options_page=2&private=poison');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('process_options.data', 2)->where('process_options.total', 27)->where('process_options.per_page', 25)->where('risk_options.total', 26)->has('risk_options.data', 1)->where('risk_options.per_page', 25)->url($base.'/plans?process_options_page=2&risk_options_page=2'));
        foreach ([$inactive->id, $foreign->id, $foreignRisk->id, 'poison'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->get($base.'/plans/'.$plan->id)->assertInertia(fn (Assert $page) => $page->where('sources.data.0.target_name', $plan->processes()->sole()->process->name)->where('sources.data.0.revision_id', $plan->processes()->sole()->bia_revision_id)->has('linked_processes', 1));
        $response = $this->get($base.'/exercises?plan_options_page=1');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('plan_options.data', 1)->where('plan_options.data.0.id', $plan->id)->where('plan_options.data.0.status', 'approved')->where('plan_options.data.0.freshness.current', true)->where('plan_options.per_page', 25));
        $this->assertStringNotContainsString($foreignPlan->id, $response->getContent());
        $this->assertSame($count, DB::table('audit_events')->count());
    }

    public function test_unrecorded_criteria_exclude_stored_results_and_foreign_exercises(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $workflow = app(BcmExerciseWorkflow::class);
        $exercise = $workflow->create($plan, ['scenario' => 'Outage', 'exercise_type' => 'tabletop', 'planned_at' => '2026-11-01', 'leader_name' => 'Lead', 'participants' => 'Team', 'criteria' => array_fill(0, 27, ['expected_result' => 'Restore'])], $actor);
        $other = $workflow->create($plan, ['criteria' => [['expected_result' => 'Foreign criterion']]], $actor);
        $exercise = $workflow->plan($exercise, 1, $actor);
        $stored = $exercise->criteria()->orderBy('position')->firstOrFail();
        $exercise = $workflow->record($exercise, ['results' => [['criterion_id' => $stored->id, 'outcome' => 'passed']]], 2, $actor);
        $count = DB::table('audit_events')->count();
        $base = "/projects/{$project->id}/bcm/exercises/{$exercise->id}";
        $response = $this->actingAs($actor)->get($base.'?unrecorded_criteria_page=2&unsafe=poison');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('unrecorded_criteria.data', 1)->where('unrecorded_criteria.total', 26)->where('unrecorded_criteria.per_page', 25)->where('unrecorded_criteria.data.0.position', 27)->where('results.data.0.criterion_id', $stored->id)->url($base.'?unrecorded_criteria_page=2'));
        $this->assertStringNotContainsString($other->criteria()->sole()->id, $response->getContent());
        $this->assertSame($count, DB::table('audit_events')->count());
    }

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
        $exercise = $w->record($exercise, ['actual_at' => '2026-11-01', 'results' => [['criterion_id' => $exercise->criteria()->sole()->id, 'outcome' => 'failed', 'observations' => 'Too slow', 'reason' => 'Delay']]], 2, $actor);
        $result = $exercise->results()->sole();
        $finding = app(FindingWorkflow::class)->proposeFromExercise($result, ['title' => 'Correction', 'description' => 'Restore faster', 'severity' => 'high'], $actor);
        $exercise = $w->complete($exercise, $exercise->lock_version, $actor);
        $base = "/projects/{$project->id}/bcm";
        $count = DB::table('audit_events')->count();
        $this->actingAs($actor)->get($base.'/plans/'.$plan->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('bcm/Plans', false)->where('revision.id', $plan->id)->where('revision.freshness.current', true)->has('revision.steps', 1)->has('revision.roles', 1)->has('sources.data', 1)->missing('revision.source_snapshot')->where('capabilities.edit', false));
        $this->get($base.'/exercises/'.$exercise->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('bcm/Exercises', false)->where('exercise.id', $exercise->id)->where('exercise.status', 'completed')->where('exercise.plan_revision_id', $plan->id)->where('results.data.0.id', $result->id)->where('results.data.0.capabilities.edit', false)->where('results.data.0.capabilities.propose_finding', false)->where('results.data.0.capabilities.waive_finding', false)->where('results.data.0.findings.data.0.id', $finding->id)->where('results.data.0.findings.data.0.capabilities.can_decide', true)->where('results.data.0.findings.data.0.capabilities.can_create_measure', false)->where('results.per_page', 25)->missing('exercise.source_snapshot'));
        $project->organization->update(['is_active' => false]);
        $this->get($base.'/exercises/'.$exercise->id)->assertInertia(fn (Assert $page) => $page->where('capabilities.write', false)->where('results.data.0.findings.data.0.capabilities.can_decide', false));
        $project->organization->update(['is_active' => true]);
        $this->assertSame($count, DB::table('audit_events')->count());
        $finding = app(FindingWorkflow::class)->decide($finding, FindingStatus::Accepted, null, $actor);
        $measure = app(MeasureWorkflow::class)->create($finding, ['title' => 'Restore sooner', 'description' => 'Improve recovery', 'priority' => 'high', 'responsible_name' => 'Operator', 'due_date' => '2026-12-01'], $actor);
        $count = DB::table('audit_events')->count();
        $this->get($base.'/exercises/'.$exercise->id)->assertInertia(fn (Assert $page) => $page->where('results.data.0.findings.data.0.capabilities.can_decide', false)->where('results.data.0.findings.data.0.capabilities.can_create_measure', true)->where('results.data.0.findings.data.0.capabilities.can_close', false)->where('results.data.0.findings.data.0.measures.data.0.id', $measure->id)->where('results.data.0.findings.data.0.measures.data.0.can_transition', true)->where('results.data.0.findings.data.0.measures.per_page', 25));
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

    public function test_bcm_url_sanitization_does_not_leak_into_next_existing_page_in_same_application(): void
    {
        [$project, $actor] = $this->bcmProject();
        $base = "/projects/{$project->id}/bcm";
        $this->actingAs($actor)->get($base.'?page=2&storage_path=poison')->assertOk()->assertInertia(fn (Assert $page) => $page->url($base.'?page=2'));

        $existingUrl = "/organizations/{$project->organization_id}/projects/{$project->id}/processes?key=Recovery&state=active";
        $this->get($existingUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->component('processes/Index', false)->where('filters.key', 'Recovery')->where('filters.state', 'active')->url($existingUrl));

        $this->get($base.'?history_page=3&storage_path=other-poison')->assertOk()->assertInertia(fn (Assert $page) => $page->url($base.'?history_page=3'));
    }

    public function test_bia_warning_exposes_only_its_server_scoped_critical_path_ids(): void
    {
        [$project, $actor] = $this->bcmProject();
        app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $edge = DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'source_asset_id' => null, 'target_process_id' => null, 'target_asset_id' => $asset->id, 'importance' => 'critical']);
        $foreign = DependencyEdge::factory()->create();
        $bia = app(BiaWorkflow::class)->draft($process, $actor);
        $count = DB::table('audit_events')->count();
        $response = $this->actingAs($actor)->get("/projects/{$project->id}/bcm/bia/{$bia->id}");
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('conflicts.data.0.code', 'dependency_target_unknown')->where('conflicts.data.0.blocking', true)->where('conflicts.data.0.target_id', $asset->id)->where('conflicts.data.0.target_name', $asset->name)->where('conflicts.data.0.path_edge_ids', [$edge->id])->where('sources.data.0.target_name', $asset->name)->missing('revision.source_snapshot'));
        $this->assertStringNotContainsString($foreign->id, $response->getContent());
        $this->assertSame($count, DB::table('audit_events')->count());
    }
}
