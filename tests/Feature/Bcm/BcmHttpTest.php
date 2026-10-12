<?php

namespace Tests\Feature\Bcm;

use App\Models\Asset;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\BcmExercise;
use App\Models\BiaRevision;
use App\Models\BusinessProcess;
use App\Models\ContinuityPlanRevision;
use App\Models\DependencyEdge;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BcmHttpTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_gets_are_read_only_and_initialization_is_explicit(): void
    {
        [$project, $actor] = $this->bcmProject();
        $base = "/projects/{$project->id}/bcm";
        foreach (['', '/bia', '/plans', '/exercises'] as $path) {
            $this->actingAs($actor)->get($base.$path)->assertOk();
        }
        $this->assertDatabaseCount('bia_configuration_versions', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->post($base.'/configuration/initialize')->assertRedirect($base);
        $this->assertSame(1, $project->fresh()->bia_configuration_lock_version);
        $this->post($base.'/configuration/activate', ['minutes' => ['60', '240'], 'lock_version' => '1'])->assertRedirect($base);
        $this->assertSame([60, 240], app(BiaConfigurationWorkflow::class)->current($project)->minutes);
        $this->post($base.'/configuration/activate', ['minutes' => [60, 480], 'lock_version' => 1])->assertStatus(409);
    }

    public function test_bia_asset_and_plan_http_contracts_normalize_minutes_and_preserve_history(): void
    {
        [$project, $actor] = $this->bcmProject();
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $base = "/projects/{$project->id}/bcm";
        $this->actingAs($actor)->post($base.'/bia', ['business_process_id' => $process->id])->assertRedirect();
        $bia = BiaRevision::query()->sole();
        $payload = $this->completeBiaData($configuration);
        $payload['rto_minutes'] = '120';
        $payload['rpo_not_applicable'] = '0';
        $payload['impacts'][0]['minutes'] = '60';
        $payload['impacts'][0]['level'] = '2';
        $this->put($base.'/bia/'.$bia->id, $payload + ['lock_version' => '1', 'source_snapshot' => ['secret' => 'client']])->assertRedirect();
        $this->post($base.'/bia/'.$bia->id.'/approve', ['lock_version' => 2])->assertRedirect();
        $this->assertSame('approved', $bia->fresh()->status->value);
        $this->assertArrayNotHasKey('secret', $bia->fresh()->source_snapshot);
        $this->post($base.'/bia/'.$bia->id.'/revise')->assertRedirect();
        $this->assertDatabaseCount('bia_revisions', 2);
        $this->post($base.'/assets/'.$asset->id.'/recovery')->assertRedirect();
        $objective = AssetRecoveryObjectiveRevision::query()->where('asset_id', $asset->id)->sole();
        $url = $base.'/assets/'.$asset->id.'/recovery/'.$objective->id;
        $this->put($url, ['rto_minutes' => '60', 'rpo_minutes' => '0', 'target_reason' => 'Reviewed', 'lock_version' => 1])->assertRedirect();
        $this->post($url.'/approve', ['lock_version' => 2])->assertRedirect();
        $this->post($base.'/plans', ['title' => 'Draft plan', 'steps' => [['expected_minutes' => '0']]])->assertRedirect();
        $plan = ContinuityPlanRevision::query()->sole();
        $this->assertSame(0, $plan->steps()->sole()->expected_minutes);
        $this->put($base.'/plans/'.$plan->id, ['lock_version' => 1, 'scenario' => 'Outage'])->assertRedirect();
        $this->assertSame('Outage', $plan->fresh()->scenario);
        $this->put($base.'/plans/'.$plan->id, ['lock_version' => 1, 'scenario' => 'Lost edit'])->assertStatus(409);
        $this->assertSame('Outage', $plan->fresh()->scenario);
    }

    public function test_invalid_containers_and_numbers_redirect_with_field_errors_without_writes(): void
    {
        [$project, $actor] = $this->bcmProject();
        app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $base = "/projects/{$project->id}/bcm";
        $this->actingAs($actor)->post($base.'/bia', ['business_process_id' => $process->id]);
        $bia = BiaRevision::query()->sole();
        foreach ([['rto_minutes' => '1.5'], ['impacts' => 'bad'], ['rpo_not_applicable' => 'yes'], ['impacts' => [['area' => 'financial', 'minutes' => true]]]] as $payload) {
            $count = DB::table('audit_events')->count();
            $this->from($base.'/bia/'.$bia->id)->put($base.'/bia/'.$bia->id, $payload + ['lock_version' => 1])->assertRedirect()->assertSessionHasErrors();
            $this->assertSame(1, $bia->fresh()->lock_version);
            $this->assertSame($count, DB::table('audit_events')->count());
        }
        $this->post($base.'/configuration/activate', ['minutes' => 'bad', 'lock_version' => 1])->assertSessionHasErrors('minutes');
        $this->post($base.'/plans', ['steps' => 'bad'])->assertSessionHasErrors('steps');
        $this->assertDatabaseCount('continuity_plan_revisions', 0);
        // Laravel's normal HTTP transport maps optional blank controls to null.
        $this->put($base.'/bia/'.$bia->id, ['rto_minutes' => '', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertNull($bia->fresh()->rto_minutes);
        $this->post($base.'/bia/'.$bia->id.'/approve', ['lock_version' => 1])->assertSessionHasErrors('rto_minutes');
    }

    public function test_active_internal_admin_and_consultant_can_accept_current_conflict_and_then_release(): void
    {
        foreach (['admin', 'consultant'] as $role) {
            [$project, $actor] = $this->bcmProject();
            $actor->forceFill(['role' => $role])->save();
            $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
            $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
            $asset = Asset::factory()->create(['project_id' => $project->id]);
            DependencyEdge::factory()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'source_asset_id' => null, 'target_process_id' => null, 'target_asset_id' => $asset->id, 'importance' => 'critical']);
            $w = app(BiaWorkflow::class);
            $bia = $w->save($w->draft($process, $actor), $this->completeBiaData($configuration), 1, $actor);
            $base = "/projects/{$project->id}/bcm/bia/{$bia->id}";
            $page = $this->actingAs($actor)->get($base)->assertOk();
            $fingerprint = $page->viewData('page')['props']['conflicts']['data'][0]['fingerprint'];
            $this->post($base.'/approve', ['lock_version' => 2])->assertSessionHasErrors('conflicts');
            $this->post($base.'/conflicts', ['fingerprint' => str_repeat('a', 64), 'reason' => 'Reviewed', 'lock_version' => 2])->assertStatus(409);
            $this->post($base.'/conflicts', ['fingerprint' => $fingerprint, 'reason' => 'Known dependency gap', 'lock_version' => 2])->assertRedirect()->assertSessionHasNoErrors();
            $this->post($base.'/approve', ['lock_version' => 3])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('approved', $bia->fresh()->status->value);
        }
    }

    public function test_exercise_transitions_proposal_waiver_and_completion_are_explicit(): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $base = "/projects/{$project->id}/bcm";
        $this->actingAs($actor)->post($base.'/exercises', ['plan_revision_id' => $plan->id, 'scenario' => 'Outage', 'exercise_type' => 'tabletop', 'planned_at' => '2026-11-01', 'leader_name' => 'Lead', 'participants' => 'Team', 'criteria' => [['expected_result' => 'Restore', 'expected_minutes' => '60'], ['expected_result' => 'Verify']]])->assertRedirect();
        $exercise = BcmExercise::query()->where('project_id', $project->id)->sole();
        $url = $base.'/exercises/'.$exercise->id;
        $this->put($url, ['lock_version' => 1, 'scenario' => 'Updated outage'])->assertRedirect();
        $this->post($url.'/plan', ['lock_version' => 2])->assertRedirect();
        $criteria = $exercise->criteria()->orderBy('position')->get();
        $this->post($url.'/record', ['lock_version' => 3, 'actual_at' => '2026-11-02', 'results' => [['criterion_id' => $criteria[0]->id, 'outcome' => 'failed', 'observations' => 'Too slow', 'reason' => 'Recovery exceeded objective', 'actual_minutes' => '70'], ['criterion_id' => $criteria[1]->id, 'outcome' => 'partial', 'observations' => 'Reviewed', 'reason' => 'Partial verification']]])->assertRedirect()->assertSessionHasNoErrors();
        $results = $exercise->results()->get()->keyBy('bcm_exercise_criterion_id');
        $this->post($url.'/results/'.$results[$criteria[0]->id]->id.'/finding', ['title' => 'Correction', 'description' => 'Restore faster', 'severity' => 'high'])->assertRedirect();
        $this->post($url.'/results/'.$results[$criteria[1]->id]->id.'/waiver', ['lock_version' => 4, 'reason' => 'No correction needed'])->assertRedirect();
        $this->post($url.'/complete', ['lock_version' => 5])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $exercise->fresh()->status->value);
        $this->assertDatabaseHas('findings', ['bcm_exercise_result_id' => $results[$criteria[0]->id]->id, 'status' => 'proposed']);
        $this->assertDatabaseCount('measures', 0);
        $this->post($url.'/cancel', ['lock_version' => 6, 'reason' => 'Changed'])->assertStatus(409);
    }
}
