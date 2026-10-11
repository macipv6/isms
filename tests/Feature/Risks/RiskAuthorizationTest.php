<?php

namespace Tests\Feature\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\ProjectStatus;
use App\Enums\RiskTriggerType;
use App\Enums\UserRole;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\Organization;
use App\Models\ProjectThreat;
use App\Models\RiskAnalysis;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskMethodologyVersion;
use App\Models\RiskTargetThreatReview;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Risks\RiskAnalysisWorkflow;
use App\Services\Risks\RiskTriggerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiskAuthorizationTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_project_scope_is_checked_before_risk_authorization(): void
    {
        [$organization, $project, $actor] = $this->context();
        $other = IsmsProject::factory()->create();
        $this->actingAs($actor)->get("/organizations/{$organization->id}/projects/{$other->id}/risks")->assertNotFound();
        $this->actingAs($actor)->post("/organizations/{$organization->id}/projects/{$other->id}/risks/context", [])->assertNotFound();
    }

    public function test_customer_cannot_read_known_project_risk_data(): void
    {
        [$organization, $project] = $this->context();
        $customer = User::factory()->for($organization)->create(['role' => UserRole::Admin]);
        $this->actingAs($customer)->get("/organizations/{$organization->id}/projects/{$project->id}/risks")->assertForbidden();
    }

    public function test_policy_reads_current_user_role_from_database(): void
    {
        [$organization, $project, $actor] = $this->context();
        DB::table('users')->where('id', $actor->id)->update(['role' => 'customer']);
        $this->actingAs($actor)->get("/organizations/{$organization->id}/projects/{$project->id}/risks")->assertForbidden();
    }

    public function test_inactive_internal_organization_cannot_read_project_or_global_risk_data(): void
    {
        [$organization, $project, $actor] = $this->context();
        $actor->organization->update(['is_active' => false]);

        $this->actingAs($actor)->get("/organizations/{$organization->id}/projects/{$project->id}/risks")->assertForbidden();
        $this->actingAs($actor)->get('/risk-methodologies')->assertForbidden();
    }

    public function test_consultant_cannot_publish_global_methodology(): void
    {
        [$organization, $project, $actor] = $this->context();
        $version = RiskMethodologyVersion::factory()->create();
        $this->actingAs($actor)->post("/risk-methodologies/{$version->id}/publish")->assertForbidden();
    }

    public function test_consultant_global_lists_exclude_unpublished_drafts(): void
    {
        [, , $actor] = $this->context();
        $methodologyDraft = RiskMethodologyVersion::factory()->create(['description' => 'Private Methodikfassung']);
        $catalogDraft = ThreatCatalogVersion::factory()->create(['description' => 'Private Katalogfassung']);

        $this->actingAs($actor)->get('/risk-methodologies')->assertOk()->assertDontSee($methodologyDraft->description);
        $this->actingAs($actor)->get('/threat-catalogs')->assertOk()->assertDontSee($catalogDraft->description);
    }

    public function test_global_draft_and_clone_validation_uses_german_messages(): void
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $admin = User::factory()->for($internal)->create(['role' => UserRole::Admin]);
        $methodology = RiskMethodologyVersion::factory()->create();
        $catalog = ThreatCatalogVersion::factory()->create();

        $this->actingAs($admin)->post("/risk-methodologies/{$methodology->id}/clone", [])
            ->assertSessionHasErrors(['description' => 'Dieses Feld ist erforderlich.']);
        $this->actingAs($admin)->put("/risk-methodologies/{$methodology->id}/draft", [])
            ->assertSessionHasErrors(['levels' => 'Dieses Feld ist erforderlich.']);
        $this->actingAs($admin)->put("/threat-catalogs/{$catalog->id}/draft", [])
            ->assertSessionHasErrors(['code' => 'Dieses Feld ist erforderlich.']);
    }

    public function test_completed_project_is_readable_but_not_writable(): void
    {
        [$organization, $project, $actor] = $this->context(ProjectStatus::Completed);
        $url = "/organizations/{$organization->id}/projects/{$project->id}/risks";
        $this->actingAs($actor)->get($url)->assertOk();
        $this->actingAs($actor)->post($url.'/context', [])->assertForbidden();
    }

    public function test_archived_project_and_inactive_customer_keep_read_only_history(): void
    {
        [$organization, $project, $actor] = $this->context(ProjectStatus::Archived);
        $organization->update(['is_active' => false]);
        $base = "/organizations/{$organization->id}/projects/{$project->id}";

        $this->actingAs($actor)->get("{$base}/risks")->assertOk();
        $this->actingAs($actor)->get("{$base}/protection-needs")->assertOk();
        $this->actingAs($actor)->post("{$base}/risks/context", [])->assertForbidden();
    }

    public function test_foreign_cycle_cannot_be_completed_through_project_route(): void
    {
        [$organization, $project, $actor] = $this->context();
        [$foreignProject, $foreignTarget, $foreignActor] = $this->riskTarget();
        $threat = ProjectThreat::query()->where('project_id', $foreignProject->id)->firstOrFail();
        $foreignAnalysis = RiskAnalysis::factory()->create(['project_id' => $foreignProject->id, 'business_process_id' => $foreignTarget->id, 'project_threat_id' => $threat->id]);
        $cycle = RiskAnalysisCycle::query()->create([
            'project_id' => $foreignProject->id,
            'risk_analysis_id' => $foreignAnalysis->id,
            'cycle_number' => 1, 'title' => $foreignAnalysis->scenario_identity, 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'confidentiality' => true, 'integrity' => false, 'availability' => false, 'status' => 'draft',
            'is_current' => true, 'needs_review' => false, 'lock_version' => 0,
            'created_by' => $foreignActor->id, 'updated_by' => $foreignActor->id,
        ]);
        $this->actingAs($actor)->post("/organizations/{$organization->id}/projects/{$project->id}/risk-cycles/{$cycle->id}/complete", ['lock_version' => 0])->assertNotFound();
    }

    public function test_foreign_nested_targets_threats_triggers_reviews_and_links_return_404(): void
    {
        [$organization, $project, $actor] = $this->context();
        [$foreignProject, $foreignTarget, $foreignActor, $foreignContext] = $this->riskTarget();
        $prefix = "/organizations/{$organization->id}/projects/{$project->id}";
        $threat = $foreignContext->projectThreats()->firstOrFail();
        $trigger = app(RiskTriggerEvaluator::class)->register($foreignProject, $foreignTarget, RiskTriggerType::NonstandardScenario, 'Fremd', $foreignActor);
        $review = RiskTargetThreatReview::query()->where('project_id', $foreignProject->id)->first();
        $cycle = $this->foreignCycle($foreignProject, $foreignTarget, $foreignActor, $threat);
        $finding = Finding::factory()->for($foreignProject, 'project')->create();
        $measure = Measure::factory()->for($foreignProject, 'project')->create(['finding_id' => $finding->id]);
        $evidence = EvidenceFile::factory()->for($foreignProject, 'project')->create();

        $routes = [
            ['POST', "{$prefix}/risks/targets/process/{$foreignTarget->id}/triggers"],
            ['PUT', "{$prefix}/risks/threats/{$threat->id}"],
            ['POST', "{$prefix}/risks/triggers/{$trigger->id}/dismiss"],
            ['PATCH', "{$prefix}/risks/reviews/{$review->id}"],
            ['POST', "{$prefix}/risk-cycles/{$cycle->id}/links/finding/{$finding->id}"],
            ['POST', "{$prefix}/risk-cycles/{$cycle->id}/links/measure/{$measure->id}"],
            ['POST', "{$prefix}/risk-cycles/{$cycle->id}/links/evidence/{$evidence->id}"],
        ];
        foreach ($routes as [$method, $url]) {
            $this->actingAs($actor)->call($method, $url, [])->assertNotFound();
        }
    }

    public function test_foreign_threat_and_trigger_payload_ids_return_not_found(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        [, , , $foreignContext] = $this->riskTarget();
        $foreignThreat = $foreignContext->projectThreats()->firstOrFail();
        $url = "/organizations/{$project->organization_id}/projects/{$project->id}/risks/targets/process/{$target->id}/analyses";
        $this->actingAs($actor)->post($url, ['threat_id' => $foreignThreat->id])->assertNotFound();
    }

    public function test_foreign_trigger_and_evidence_payload_ids_return_not_found(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        [$foreignProject, $foreignTarget, $foreignActor] = $this->riskTarget();
        $foreignTrigger = app(RiskTriggerEvaluator::class)->register($foreignProject, $foreignTarget, RiskTriggerType::Manual, 'Fremd', $foreignActor);
        $foreignEvidence = EvidenceFile::factory()->for($foreignProject, 'project')->create();
        $threat = $context->projectThreats()->firstOrFail();
        $cycle = $this->foreignCycle($project, $target, $actor, $threat);
        $base = "/organizations/{$project->organization_id}/projects/{$project->id}";

        $this->actingAs($actor)->post("{$base}/risks/targets/process/{$target->id}/analyses", [
            'threat_id' => $threat->id, 'trigger_ids' => [$foreignTrigger->id],
        ])->assertNotFound();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/treatment", [
            'action' => 'avoid', 'cessation_evidence_id' => $foreignEvidence->id,
        ])->assertNotFound();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/decisions", [
            'channel' => 'external', 'evidence_id' => $foreignEvidence->id,
        ])->assertNotFound();
    }

    private function foreignCycle(IsmsProject $project, DependencyNode $target, User $actor, ProjectThreat $threat): RiskAnalysisCycle
    {
        return app(RiskAnalysisWorkflow::class)->create($project, $target, $threat, [
            'title' => fake()->unique()->words(3, true), 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'confidentiality' => true, 'integrity' => false, 'availability' => false,
        ], [], $actor);
    }

    private function context(ProjectStatus $status = ProjectStatus::Draft): array
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create(['status' => $status]);
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Consultant]);

        return [$organization, $project, $actor];
    }
}
