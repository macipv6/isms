<?php

namespace Tests\Feature\Risks;

use App\Enums\RiskTriggerType;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskAnalysisTrigger;
use App\Services\Risks\RiskAnalysisWorkflow;
use App\Services\Risks\RiskTriggerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiskHttpWorkflowTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_voluntary_analysis_accepts_missing_and_empty_trigger_lists(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $url = "/organizations/{$project->organization_id}/projects/{$project->id}/risks/targets/process/{$target->id}/analyses";
        $payload = [
            'threat_id' => $context->projectThreats()->firstOrFail()->id,
            'title' => 'Freiwillige Analyse', 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'confidentiality' => false, 'integrity' => false, 'availability' => true,
        ];
        $mandatory = app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Abweichendes Szenario', $actor);

        $this->actingAs($actor)->post($url, $payload)->assertSessionHasNoErrors();
        $this->actingAs($actor)->post($url, [...$payload, 'title' => 'Zweite freiwillige Analyse', 'trigger_ids' => []])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('risk_analysis_cycles', 2);
        $this->assertDatabaseCount('risk_analysis_trigger_links', 0);
        $this->assertSame('open', $mandatory->fresh()->status->value);
    }

    public function test_oversized_trigger_list_is_rejected_without_per_id_lookup_and_duplicates_are_rejected(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $url = "/organizations/{$project->organization_id}/projects/{$project->id}/risks/targets/process/{$target->id}/analyses";
        $payload = [
            'threat_id' => $context->projectThreats()->firstOrFail()->id,
            'title' => 'Grenztest', 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'confidentiality' => false, 'integrity' => false, 'availability' => true,
        ];
        $triggerQueries = 0;
        DB::listen(function ($query) use (&$triggerQueries): void {
            if (str_contains($query->sql, 'risk_analysis_triggers')) {
                $triggerQueries++;
            }
        });

        $this->actingAs($actor)->post($url, [...$payload, 'trigger_ids' => array_map(fn (): string => (string) Str::uuid(), range(1, 101))])
            ->assertSessionHasErrors(['trigger_ids' => 'Dieses Feld ist zu lang.']);
        $this->assertLessThanOrEqual(2, $triggerQueries);

        $trigger = app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::Manual, 'Prüfhinweis', $actor);
        $this->actingAs($actor)->post($url, [...$payload, 'trigger_ids' => [$trigger->id, $trigger->id]])
            ->assertSessionHasErrors('trigger_ids.0');
    }

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
