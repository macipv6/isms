<?php

namespace Tests\Feature\Risks;

use App\Enums\FindingStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingRiskTargetHttpTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_accepted_finding_can_link_to_process_and_asset_and_open_mandatory_triggers(): void
    {
        [$project, $process, $actor] = $this->riskTarget();
        $finding = Finding::factory()->for($project, 'project')->create(['status' => FindingStatus::Accepted]);
        $asset = Asset::factory()->for($project, 'project')->create();
        $base = "/organizations/{$project->organization_id}/projects/{$project->id}/risks/findings/{$finding->id}/targets";

        $this->actingAs($actor)->post("{$base}/process/{$process->id}")->assertSessionHasNoErrors();
        $this->actingAs($actor)->post("{$base}/asset/{$asset->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseHas('finding_process_links', ['project_id' => $project->id, 'finding_id' => $finding->id, 'business_process_id' => $process->id]);
        $this->assertDatabaseHas('finding_asset_links', ['project_id' => $project->id, 'finding_id' => $finding->id, 'asset_id' => $asset->id]);
        $this->assertDatabaseHas('risk_analysis_triggers', ['project_id' => $project->id, 'source_finding_id' => $finding->id, 'business_process_id' => $process->id, 'type' => 'insufficient_baseline_coverage', 'status' => 'open']);
        $this->assertDatabaseHas('risk_analysis_triggers', ['project_id' => $project->id, 'source_finding_id' => $finding->id, 'asset_id' => $asset->id, 'type' => 'insufficient_baseline_coverage', 'status' => 'open']);
    }

    public function test_foreign_finding_and_target_are_not_found_before_authorization(): void
    {
        [$project, $process, $actor] = $this->riskTarget();
        [$foreignProject, $foreignProcess] = $this->riskTarget();
        $foreignAsset = Asset::factory()->for($foreignProject, 'project')->create();
        $finding = Finding::factory()->for($project, 'project')->create(['status' => FindingStatus::Accepted]);
        $foreignFinding = Finding::factory()->for($foreignProject, 'project')->create(['status' => FindingStatus::Accepted]);
        $base = "/organizations/{$project->organization_id}/projects/{$project->id}/risks/findings";

        $this->actingAs($actor)->post("{$base}/{$foreignFinding->id}/targets/process/{$process->id}")->assertNotFound();
        $this->actingAs($actor)->post("{$base}/{$finding->id}/targets/process/{$foreignProcess->id}")->assertNotFound();
        $this->actingAs($actor)->post("{$base}/{$finding->id}/targets/asset/{$foreignAsset->id}")->assertNotFound();
        $this->assertDatabaseCount('finding_process_links', 0);
    }

    public function test_known_unauthorized_and_completed_or_archived_project_writes_are_forbidden(): void
    {
        [$project, $process, $actor] = $this->riskTarget();
        $finding = Finding::factory()->for($project, 'project')->create(['status' => FindingStatus::Accepted]);
        $url = "/organizations/{$project->organization_id}/projects/{$project->id}/risks/findings/{$finding->id}/targets/process/{$process->id}";

        $customer = User::factory()->for($project->organization)->create(['role' => UserRole::Admin]);
        $this->actingAs($customer)->post($url)->assertForbidden();
        foreach ([ProjectStatus::Completed, ProjectStatus::Archived] as $status) {
            $project->update(['status' => $status]);
            $this->actingAs($actor)->post($url)->assertForbidden();
        }
        $this->assertDatabaseCount('finding_process_links', 0);
    }

    public function test_closed_finding_cannot_gain_a_new_risk_target(): void
    {
        [$project, $process, $actor] = $this->riskTarget();
        $finding = Finding::factory()->for($project, 'project')->create(['status' => FindingStatus::Closed]);
        $url = "/organizations/{$project->organization_id}/projects/{$project->id}/risks/findings/{$finding->id}/targets/process/{$process->id}";

        $this->actingAs($actor)->post($url)->assertSessionHasErrors('finding');
        $this->assertDatabaseCount('finding_process_links', 0);
    }
}
