<?php

namespace Tests\Feature\Risks;

use App\Enums\ProjectStatus;
use App\Enums\RiskTreatmentAction;
use App\Enums\RiskTriggerType;
use App\Models\EvidenceFile;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskTreatmentWorkflow;
use App\Services\Risks\RiskTriggerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RiskWorkspacePageTest extends TestCase
{
    use InteractsWithRiskTreatment;
    use RefreshDatabase;

    public function test_workspace_exposes_pinned_matrix_review_workflow_and_decision_permissions_without_private_file_metadata(): void
    {
        [$project, $cycle, $actor, $target] = $this->assessedCycle();
        app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Sonderfall', $actor);
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Ausgangswert bleibt'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle->fresh(), 'frequent', 'considerable', 'Management erforderlich', $actor, $cycle->fresh()->lock_version);
        EvidenceFile::factory()->for($project, 'project')->create(['storage_path' => 'private/hidden.pdf', 'sha256' => str_repeat('b', 64)]);
        $url = "/organizations/{$project->organization_id}/projects/{$project->id}/risks";
        $response = $this->actingAs($actor)->get($url.'?category=very_high&decision_required=yes&cause=private-cause');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('risks/Index')
            ->where('filters.category', 'very_high')->where('filters.decision_required', 'yes')
            ->where('capabilities.write', true)->where('targets.0.id', $target->id)
            ->has('targets.0.reviews')->has('methodologies.0.cells', 16)
            ->where('cycles.data.0.id', $cycle->id)->where('cycles.data.0.decisionRequired', true)
            ->where('cycles.data.0.canDecideInternal', false)->where('cycles.data.0.canDecideExternal', true)
            ->where('cycles.data.0.treatment.rationale', 'Ausgangswert bleibt')
            ->has('cycles.data.0.assessments', 2)->has('cycles.data.0.completionBlockers', 1)
            ->missing('resources.evidence.0.storage_path')->missing('resources.evidence.0.sha256'));
        $this->assertStringNotContainsString('private-cause', $response->getContent());
        $this->assertStringNotContainsString('private/hidden.pdf', $response->getContent());
        $this->actingAs($actor)->get($url.'?category=low')->assertInertia(fn (Assert $page) => $page->has('cycles.data', 0));
        $project->update(['status' => ProjectStatus::Completed]);
        $this->actingAs($actor)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('capabilities.write', false)->where('cycles.data.0.canDecideExternal', false)
            ->has('cycles.data.0.assessments', 2));
    }

    public function test_read_only_history_exposes_saved_transfer_and_cessation_details_without_evidence_metadata(): void
    {
        [$project, $cycle, $actor, $target] = $this->assessedCycle('rare', 'limited');
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Transfer, [
            'rationale' => 'Vertrag deckt Ausfall', 'counterparty_name' => 'Vertragspartner',
            'counterparty_email' => 'kontakt@example.test',
        ], $actor);
        $proof = EvidenceFile::factory()->for($project, 'project')->create([
            'status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            'storage_path' => 'private/cessation-secret.pdf', 'sha256' => str_repeat('c', 64),
        ]);
        $threat = \App\Models\ProjectThreat::query()->where('project_id', $project->id)->firstOrFail();
        $avoided = app(\App\Services\Risks\RiskAnalysisWorkflow::class)->create($project, $target, $threat, [
            'title' => 'Stilllegung', 'cause' => 'Altbetrieb', 'event' => 'Ausfall', 'impact' => 'Unterbrechung',
            'confidentiality' => false, 'integrity' => false, 'availability' => true,
        ], [], $actor);
        app(RiskAssessmentService::class)->assessInherent($avoided, 'rare', 'limited', 'Ausgangslage', $actor, 0);
        app(RiskTreatmentWorkflow::class)->select($avoided->fresh(), RiskTreatmentAction::Avoid, [
            'rationale' => 'Betrieb eingestellt', 'cessation_statement' => 'Dienst wurde stillgelegt',
            'cessation_evidence_id' => $proof->id,
        ], $actor);
        $project->update(['status' => ProjectStatus::Completed]);
        $response = $this->actingAs($actor)->get("/organizations/{$project->organization_id}/projects/{$project->id}/risks");
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('capabilities.write', false)
            ->where('cycles.data', function ($rows) use ($proof): bool {
                $treatments = collect($rows)->pluck('treatment')->keyBy('action');

                return $treatments['transfer']['rationale'] === 'Vertrag deckt Ausfall'
                    && $treatments['transfer']['counterparty_name'] === 'Vertragspartner'
                    && $treatments['transfer']['counterparty_email'] === 'kontakt@example.test'
                    && $treatments['avoid']['cessation_statement'] === 'Dienst wurde stillgelegt'
                    && $treatments['avoid']['cessation_evidence_id'] === $proof->id;
            }));
        $this->assertStringNotContainsString('private/cessation-secret.pdf', $response->getContent());
        $this->assertStringNotContainsString(str_repeat('c', 64), $response->getContent());
    }
}
