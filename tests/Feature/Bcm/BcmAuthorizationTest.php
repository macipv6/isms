<?php

namespace Tests\Feature\Bcm;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcm\AssetRecoveryWorkflow;
use App\Services\Bcm\BcmExerciseWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BcmAuthorizationTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public static function deniedActors(): array
    {
        return array_map(fn ($case) => [$case], ['customer', 'inactive_actor', 'inactive_internal_org', 'inactive_customer_org', 'completed', 'archived']);
    }

    #[DataProvider('deniedActors')]
    public function test_every_mutation_denies_current_access_without_changing_rows_or_audits(string $case): void
    {
        [$project, $actor] = $this->bcmProject();
        $plan = $this->approvedPlan($project, $actor);
        $bia = $plan->processes()->sole()->bia;
        $asset = Asset::factory()->create(['project_id' => $project->id]);
        $objective = app(AssetRecoveryWorkflow::class)->draft($asset, $actor);
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($plan, ['scenario' => 'Outage', 'exercise_type' => 'tabletop', 'planned_at' => '2026-11-01', 'leader_name' => 'Lead', 'participants' => 'Team', 'criteria' => [['expected_result' => 'Restore']]], $actor);
        $exercise = $w->plan($exercise, 1, $actor);
        $exercise = $w->record($exercise, ['results' => [['criterion_id' => $exercise->criteria()->sole()->id, 'outcome' => 'failed', 'observations' => 'Failed']]], 2, $actor);
        $result = $exercise->results()->sole();
        match ($case) {
            'customer' => $actor->forceFill(['organization_id' => $project->organization_id])->save(),
            'inactive_actor' => User::query()->whereKey($actor->id)->update(['is_active' => false]),
            'inactive_internal_org' => Organization::query()->whereKey($actor->organization_id)->update(['is_active' => false]),
            'inactive_customer_org' => Organization::query()->whereKey($project->organization_id)->update(['is_active' => false]),
            default => $project->forceFill(['status' => $case])->save(),
        };
        // Deliberately authenticate the old instance; policy must reload it.
        $this->actingAs($actor);
        $base = "/projects/{$project->id}/bcm";
        $before = $this->state();
        foreach ($this->actions($bia->id, $asset->id, $objective->id, $plan->id, $exercise->id, $result->id) as [$method, $path]) {
            $this->call($method, $base.$path, ['lock_version' => 3, 'minutes' => [60, 240], 'business_process_id' => $bia->analysis->business_process_id, 'plan_revision_id' => $plan->id, 'reason' => 'Reviewed', 'fingerprint' => str_repeat('a', 64)])->assertForbidden();
            $this->assertSame($before, $this->state(), $method.' '.$path.' changed persisted data');
        }
        if (in_array($case, ['completed', 'archived', 'inactive_customer_org'], true)) {
            foreach (['', '/bia', '/bia/'.$bia->id, '/assets/'.$asset->id.'/recovery/'.$objective->id, '/plans', '/plans/'.$plan->id, '/exercises', '/exercises/'.$exercise->id] as $path) {
                $this->get($base.$path)->assertOk();
            }
        } else {
            $this->get($base)->assertForbidden();
        }
        $this->assertSame($before, $this->state());
    }

    public function test_foreign_nested_resources_and_wrong_same_project_parent_are_404_before_validation(): void
    {
        [$project, $actor] = $this->bcmProject();
        [$foreign] = $this->bcmProject();
        $plan = $this->approvedPlan($foreign, $actor);
        $bia = $plan->processes()->sole()->bia;
        $asset = Asset::factory()->create(['project_id' => $foreign->id]);
        $objective = app(AssetRecoveryWorkflow::class)->draft($asset, $actor);
        $w = app(BcmExerciseWorkflow::class);
        $exercise = $w->create($plan, ['scenario' => 'Outage', 'exercise_type' => 'tabletop', 'planned_at' => '2026-11-01', 'leader_name' => 'Lead', 'participants' => 'Team', 'criteria' => [['expected_result' => 'Restore']]], $actor);
        $exercise = $w->plan($exercise, 1, $actor);
        $exercise = $w->record($exercise, ['results' => [['criterion_id' => $exercise->criteria()->sole()->id, 'outcome' => 'failed', 'observations' => 'Failed']]], 2, $actor);
        $result = $exercise->results()->sole();
        $other = $w->create($plan, [], $actor);
        $base = "/projects/{$project->id}/bcm";
        $this->actingAs($actor);
        $before = $this->state();
        foreach ($this->actions($bia->id, $asset->id, $objective->id, $plan->id, $exercise->id, $result->id) as [$method, $path]) {
            if (in_array($path, ['/configuration/initialize', '/configuration/activate', '/plans', '/exercises', '/bia'], true)) {
                continue;
            }
            $this->call($method, $base.$path, [])->assertNotFound();
        }
        $this->post($base.'/bia', ['business_process_id' => $bia->analysis->business_process_id])->assertNotFound();
        $this->post($base.'/exercises', ['plan_revision_id' => $plan->id])->assertNotFound();
        $localAsset = Asset::factory()->create(['project_id' => $foreign->id]);
        $this->put("/projects/{$foreign->id}/bcm/assets/{$localAsset->id}/recovery/{$objective->id}", [])->assertNotFound();
        foreach (['finding', 'waiver'] as $action) {
            $this->post("/projects/{$foreign->id}/bcm/exercises/{$other->id}/results/{$result->id}/{$action}", [])->assertNotFound();
        }
        foreach (['/bia/'.$bia->id, '/assets/'.$asset->id.'/recovery/'.$objective->id, '/plans/'.$plan->id, '/exercises/'.$exercise->id] as $path) {
            $this->get($base.$path)->assertNotFound();
        }
        $this->get('/projects/not-a-uuid/bcm')->assertNotFound();
        $this->assertSame($before, $this->state());
    }

    private function actions(string $bia, string $asset, string $objective, string $plan, string $exercise, string $result): array
    {
        return [['POST', '/configuration/initialize'], ['POST', '/configuration/activate'], ['POST', '/bia'], ['PUT', '/bia/'.$bia], ['POST', '/bia/'.$bia.'/approve'], ['POST', '/bia/'.$bia.'/revise'], ['POST', '/bia/'.$bia.'/conflicts'], ['POST', '/assets/'.$asset.'/recovery'], ['PUT', '/assets/'.$asset.'/recovery/'.$objective], ['POST', '/assets/'.$asset.'/recovery/'.$objective.'/approve'], ['POST', '/plans'], ['PUT', '/plans/'.$plan], ['POST', '/plans/'.$plan.'/approve'], ['POST', '/plans/'.$plan.'/revise'], ['POST', '/exercises'], ['PUT', '/exercises/'.$exercise], ['POST', '/exercises/'.$exercise.'/plan'], ['POST', '/exercises/'.$exercise.'/record'], ['POST', '/exercises/'.$exercise.'/complete'], ['POST', '/exercises/'.$exercise.'/cancel'], ['POST', '/exercises/'.$exercise.'/results/'.$result.'/finding'], ['POST', '/exercises/'.$exercise.'/results/'.$result.'/waiver']];
    }

    private function state(): array
    {
        $state = [];
        foreach (['isms_projects', 'bia_configuration_versions', 'business_impact_analyses', 'bia_revisions', 'bia_impacts', 'asset_recovery_objective_revisions', 'bcm_conflict_acceptances', 'continuity_plans', 'continuity_plan_revisions', 'continuity_plan_steps', 'continuity_plan_roles', 'continuity_plan_processes', 'bcm_exercises', 'bcm_exercise_criteria', 'bcm_exercise_results', 'findings', 'audit_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $state;
    }
}
