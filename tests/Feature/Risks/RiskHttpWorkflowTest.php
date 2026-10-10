<?php

namespace Tests\Feature\Risks;

use App\Models\RiskAnalysisCycle;
use App\Models\RiskAnalysisTrigger;
use App\Services\Risks\RiskAnalysisWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskHttpWorkflowTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_invalid_treatment_enum_has_a_german_field_error(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $threat = $context->projectThreats()->firstOrFail();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $threat, [
            'title' => 'Testfall', 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'confidentiality' => true, 'integrity' => false, 'availability' => false,
        ], [], $actor);

        $this->actingAs($actor)->post("/organizations/{$project->organization_id}/projects/{$project->id}/risk-cycles/{$cycle->id}/treatment", [
            'action' => 'unknown', 'rationale' => 'Begründung',
        ])->assertSessionHasErrors(['action' => 'Der gewählte Wert ist ungültig.']);
    }

    public function test_cycle_completion_requires_lock_version_with_german_error(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $threat = $context->projectThreats()->firstOrFail();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $threat, [
            'title' => 'Testfall', 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'confidentiality' => true, 'integrity' => false, 'availability' => false,
        ], [], $actor);

        $this->actingAs($actor)->post("/organizations/{$project->organization_id}/projects/{$project->id}/risk-cycles/{$cycle->id}/complete", [])
            ->assertSessionHasErrors(['lock_version' => 'Dieses Feld ist erforderlich.']);
    }

    public function test_normal_risk_is_assessed_treated_and_completed_through_http(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $base = "/organizations/{$project->organization_id}/projects/{$project->id}";
        $threat = $context->projectThreats()->firstOrFail();
        $this->actingAs($actor)->post("{$base}/risks/targets/process/{$target->id}/triggers", ['type' => 'manual', 'rationale' => 'Prüfhinweis'])->assertRedirect();
        $trigger = RiskAnalysisTrigger::query()->where('project_id', $project->id)->sole();
        $this->actingAs($actor)->post("{$base}/risks/targets/process/{$target->id}/analyses", [
            'threat_id' => $threat->id, 'trigger_ids' => [$trigger->id],
            'title' => 'Kurzzeitiger Ausfall', 'cause' => 'Netzunterbrechung', 'event' => 'System steht', 'impact' => 'Kurzzeitige Verzögerung',
            'confidentiality' => false, 'integrity' => false, 'availability' => true,
        ])->assertRedirect();
        $cycle = RiskAnalysisCycle::query()->where('project_id', $project->id)->sole();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/assessments", [
            'phase' => 'inherent', 'frequency_key' => 'rare', 'impact_key' => 'limited', 'rationale' => 'Begrenzte Exposition', 'lock_version' => 0,
        ])->assertRedirect();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/treatment", ['action' => 'accept', 'rationale' => 'Bewusst übernommen'])->assertRedirect();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/assessments", [
            'phase' => 'residual', 'frequency_key' => 'rare', 'impact_key' => 'limited', 'rationale' => 'Unverändert gering', 'lock_version' => $cycle->fresh()->lock_version,
        ])->assertRedirect();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/complete", ['lock_version' => $cycle->fresh()->lock_version])->assertRedirect();
        $this->assertSame('completed', $cycle->fresh()->status->value);
    }

    public function test_high_risk_external_decision_is_recorded_then_cycle_completes(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $base = "/organizations/{$project->organization_id}/projects/{$project->id}";
        $threat = $context->projectThreats()->firstOrFail();
        $this->actingAs($actor)->post("{$base}/risks/targets/process/{$target->id}/triggers", ['type' => 'manual', 'rationale' => 'Prüfhinweis'])->assertRedirect();
        $trigger = RiskAnalysisTrigger::query()->where('project_id', $project->id)->sole();
        $this->actingAs($actor)->post("{$base}/risks/targets/process/{$target->id}/analyses", [
            'threat_id' => $threat->id, 'trigger_ids' => [$trigger->id],
            'title' => 'Produktionsausfall', 'cause' => 'Netzunterbrechung', 'event' => 'System steht', 'impact' => 'Erheblicher Ausfall',
            'confidentiality' => false, 'integrity' => false, 'availability' => true,
        ])->assertRedirect();
        $cycle = RiskAnalysisCycle::query()->where('project_id', $project->id)->sole();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/assessments", [
            'phase' => 'inherent', 'frequency_key' => 'frequent', 'impact_key' => 'considerable', 'rationale' => 'Hohe Belastung', 'lock_version' => 0,
        ])->assertRedirect();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/treatment", ['action' => 'accept', 'rationale' => 'Bewusst übernommen'])->assertRedirect();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/assessments", [
            'phase' => 'residual', 'frequency_key' => 'frequent', 'impact_key' => 'considerable', 'rationale' => 'Restrisiko hoch', 'lock_version' => $cycle->fresh()->lock_version,
        ])->assertRedirect();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/decisions", [
            'channel' => 'internal', 'status' => 'accepted', 'decision_date' => '2026-10-08', 'rationale' => 'Eigene Freigabe',
        ])->assertForbidden();
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/decisions", [
            'channel' => 'external', 'status' => 'accepted', 'name' => 'Kundenperson', 'function' => 'Geschäftsleitung',
            'decision_date' => '2026-10-08', 'rationale' => 'Externe Freigabe',
        ])->assertRedirect();
        $this->assertDatabaseHas('risk_decisions', ['risk_analysis_cycle_id' => $cycle->id, 'channel' => 'external', 'external_decision_maker_name' => 'Kundenperson']);
        $this->actingAs($actor)->post("{$base}/risk-cycles/{$cycle->id}/complete", ['lock_version' => $cycle->fresh()->lock_version])->assertRedirect();
        $this->assertSame('completed', $cycle->fresh()->status->value);
    }
}
