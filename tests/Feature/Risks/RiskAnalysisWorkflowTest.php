<?php

namespace Tests\Feature\Risks;

use App\Enums\RiskCycleStatus;
use App\Enums\RiskTriggerType;
use App\Enums\ThreatReviewStatus;
use App\Models\AuditEvent;
use App\Models\RiskAnalysis;
use App\Models\RiskTargetThreatReview;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Risks\RiskAnalysisWorkflow;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskChangeInvalidator;
use App\Services\Risks\RiskTriggerEvaluator;
use App\Services\Threats\ThreatOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class RiskAnalysisWorkflowTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    private function scenario(string $title = 'Stromausfall'): array
    {
        return ['title' => $title, 'cause' => 'Netzausfall', 'event' => 'Anlage stoppt', 'impact' => 'Dienst fällt aus', 'confidentiality' => false, 'integrity' => false, 'availability' => true];
    }

    public function test_inherent_risk_is_derived_from_the_pinned_matrix_and_audited_without_text(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $threat = $context->projectThreats()->firstOrFail();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $threat, [...$this->scenario(), 'risk_category' => 'low'], [], $actor);
        $assessment = app(RiskAssessmentService::class)->assessInherent($cycle, 'frequent', 'considerable', 'Observed exposure and material impact', $actor, $cycle->lock_version);
        $this->assertSame('very_high', $assessment->riskLevel->key);
        $this->assertSame(RiskCycleStatus::InherentAssessed, $cycle->fresh()->status);
        $this->assertSame(1, $cycle->fresh()->lock_version);
        $this->assertSame(1, $cycle->assessments()->count());
        $this->assertStringNotContainsString('Stromausfall', json_encode(AuditEvent::query()->where('event_type', 'risk_analysis.created')->sole()->context));
        $this->expectException(ValidationException::class);
        app(RiskAssessmentService::class)->assessInherent($cycle, 'frequent', 'considerable', 'Duplicate', $actor, 0);
    }

    public function test_scenario_identity_and_next_cycle_copy_only_scenario(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $threat = $context->projectThreats()->firstOrFail();
        $workflow = app(RiskAnalysisWorkflow::class);
        $first = $workflow->create($project, $target, $threat, $this->scenario(), [], $actor);
        try {
            $workflow->create($project, $target, $threat, $this->scenario(' stromAUSfall '), [], $actor);
            $this->fail('Duplicate scenario title accepted.');
        } catch (ValidationException) {
            $this->assertSame(1, RiskAnalysis::query()->count());
        }
        $workflow->create($project, $target, $threat, $this->scenario('Brand'), [], $actor);
        app(RiskAssessmentService::class)->assessInherent($first, 'frequent', 'considerable', 'Material', $actor, 0);
        $first->update(['status' => RiskCycleStatus::Completed, 'completed_by' => $actor->id, 'completed_at' => now()]);
        $next = $workflow->startNextCycle($first->analysis, $actor);
        $this->assertSame(2, $next->cycle_number);
        $this->assertSame($first->title, $next->title);
        $this->assertSame($first->cause, $next->cause);
        $this->assertSame(0, $next->assessments()->count());
        $this->assertSame(RiskCycleStatus::Draft, $next->status);
    }

    public function test_create_rejects_trigger_from_another_target(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $trigger = app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::Manual, 'Hinweis', $actor);
        $threat = $context->projectThreats()->firstOrFail();
        $this->expectException(ValidationException::class);
        app(RiskAnalysisWorkflow::class)->create($project, $target, $threat, $this->scenario(), [$trigger->id, $trigger->id], $actor);
    }

    public function test_applicable_threat_requires_a_current_non_stale_cycle_for_overview(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Sonderfall', $actor);
        $review = RiskTargetThreatReview::query()->where('business_process_id', $target->id)->firstOrFail();
        RiskTargetThreatReview::query()->where('business_process_id', $target->id)->whereKeyNot($review->id)->update(['status' => ThreatReviewStatus::NotApplicable->value, 'rationale' => 'Keine Exposition']);
        app(ThreatOverviewService::class)->review($review, ThreatReviewStatus::Applicable, null, $actor);
        $this->assertFalse(app(ThreatOverviewService::class)->isComplete($project, $target));
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $review->threat, $this->scenario(), [], $actor);
        $this->assertTrue(app(ThreatOverviewService::class)->isComplete($project, $target));
        app(RiskChangeInvalidator::class)->invalidateForTarget($project, $target, 'topology', $actor);
        $this->assertFalse(app(ThreatOverviewService::class)->isComplete($project, $target));
    }

    public function test_assessment_and_status_roll_back_when_redacted_audit_fails(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [], $actor);
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                if ($eventType === 'risk_analysis.inherent_assessed') {
                    throw new RuntimeException('Audit unavailable');
                }

                return parent::record($eventType, $actor, $context, $organizationId);
            }
        });
        try {
            app(RiskAssessmentService::class)->assessInherent($cycle, 'frequent', 'considerable', 'Betriebliche Bewertung', $actor, 0);
            $this->fail('Assessment survived audit failure.');
        } catch (RuntimeException) {
            $this->assertSame(RiskCycleStatus::Draft, $cycle->fresh()->status);
            $this->assertSame(0, $cycle->assessments()->count());
        }
    }

    public function test_create_rechecks_actor_and_project_from_database(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [], $actor);
            $this->fail('Inactive actor accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('risk_analyses', 0);
        }
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        DB::table('isms_projects')->where('id', $project->id)->update(['status' => 'completed']);
        $this->expectException(ValidationException::class);
        app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [], $actor);
    }

    public function test_manual_trigger_linked_to_completed_cycle_cannot_be_dismissed(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $triggers = app(RiskTriggerEvaluator::class);
        $trigger = $triggers->register($project, $target, RiskTriggerType::Manual, 'Hinweis', $actor);
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [$trigger->id], $actor);
        $cycle->update(['status' => RiskCycleStatus::Completed, 'completed_by' => $actor->id, 'completed_at' => now()]);
        $this->expectException(ValidationException::class);
        $triggers->dismiss($trigger, $actor);
    }

    public function test_material_change_supersedes_draft_and_allows_clean_next_cycle(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $workflow = app(RiskAnalysisWorkflow::class);
        $trigger = app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Aktueller Auslöser', $actor);
        $draft = $workflow->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [$trigger->id], $actor);
        app(RiskChangeInvalidator::class)->invalidateForTarget($project, $target, 'topology', $actor);
        $this->assertSame(RiskCycleStatus::Superseded, $draft->fresh()->status);
        $this->assertFalse($draft->fresh()->is_current);
        $this->assertTrue($draft->fresh()->needs_review);
        $this->assertSame(1, $draft->fresh()->lock_version);
        $next = $workflow->startNextCycle($draft->analysis, $actor);
        $this->assertSame(2, $next->cycle_number);
        $this->assertSame(RiskCycleStatus::Draft, $next->status);
        $this->assertSame(0, $next->assessments()->count());
        $this->assertSame([$trigger->id], $next->triggerLinks()->pluck('risk_analysis_trigger_id')->all());
        $this->assertSame(1, $draft->analysis->cycles()->where('is_current', true)->count());
    }

    public function test_material_change_supersedes_inherent_assessment_without_copying_it(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $workflow = app(RiskAnalysisWorkflow::class);
        $cycle = $workflow->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [], $actor);
        app(RiskAssessmentService::class)->assessInherent($cycle, 'frequent', 'considerable', 'Bewertung', $actor, 0);
        app(RiskChangeInvalidator::class)->invalidateForTarget($project, $target, 'provenance', $actor);
        $this->assertSame(RiskCycleStatus::Superseded, $cycle->fresh()->status);
        $this->assertSame(1, $cycle->assessments()->count());
        $next = $workflow->startNextCycle($cycle->analysis, $actor);
        $this->assertSame(2, $next->cycle_number);
        $this->assertSame(0, $next->assessments()->count());
    }

    public function test_next_cycle_copies_only_current_trigger_links_after_invalidation(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $triggers = app(RiskTriggerEvaluator::class);
        $current = $triggers->register($project, $target, RiskTriggerType::NonstandardScenario, 'Aktueller Auslöser', $actor);
        $obsolete = $triggers->register($project, $target, RiskTriggerType::Manual, 'Alter Auslöser', $actor);
        $workflow = app(RiskAnalysisWorkflow::class);
        $old = $workflow->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [$current->id, $obsolete->id], $actor);
        $old->update(['status' => RiskCycleStatus::Completed, 'completed_by' => $actor->id, 'completed_at' => now()]);
        DB::table('risk_analysis_triggers')->where('id', $obsolete->id)->update(['status' => 'superseded']);
        app(RiskChangeInvalidator::class)->invalidateForTarget($project, $target, 'topology', $actor);
        $next = $workflow->startNextCycle($old->analysis, $actor);
        $this->assertSame([$current->id], $next->triggerLinks()->pluck('risk_analysis_trigger_id')->all());
        $this->assertSame(2, $old->triggerLinks()->count());
        $this->assertSame(0, $next->assessments()->count());
    }

    public function test_scenario_validation_has_stable_german_messages(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        try {
            app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), [...$this->scenario(), 'title' => null], [], $actor);
            $this->fail('Missing title accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('Dieses Feld ist erforderlich.', $exception->errors()['title'][0]);
        }
    }
}
