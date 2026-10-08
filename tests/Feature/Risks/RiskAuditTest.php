<?php

namespace Tests\Feature\Risks;

use App\Enums\ProjectStatus;
use App\Enums\RiskDecisionStatus;
use App\Enums\RiskTreatmentAction;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Projects\ProjectWorkflow;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskCycleCompleter;
use App\Services\Risks\RiskDecisionWorkflow;
use App\Services\Risks\RiskTreatmentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RiskAuditTest extends TestCase
{
    use InteractsWithRiskTreatment;
    use RefreshDatabase;

    public function test_treatment_and_residual_audit_omit_sensitive_text(): void
    {
        [, $cycle, $actor] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'SECRETRATIONALE'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'frequent', 'considerable', 'SECRETRESIDUAL', $actor, $cycle->fresh()->lock_version);
        $contexts = AuditEvent::query()->whereIn('event_type', ['risk_analysis.treatment_selected', 'risk_analysis.residual_assessed'])->pluck('context')->toJson();
        foreach (['SECRETRATIONALE', 'SECRETRESIDUAL', 'Vertrauliches Szenario', 'Ursache geheim'] as $secret) {
            $this->assertStringNotContainsString($secret, $contexts);
        }
        $this->assertSame(2, AuditEvent::query()->whereIn('event_type', ['risk_analysis.treatment_selected', 'risk_analysis.residual_assessed'])->count());
    }

    public function test_audit_failure_rolls_back_treatment_write(): void
    {
        [, $cycle, $actor] = $this->assessedCycle();
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                if ($eventType === 'risk_analysis.treatment_selected') {
                    throw new RuntimeException('Audit unavailable');
                }

                return parent::record($eventType, $actor, $context, $organizationId);
            }
        });
        try {
            app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Rollback'], $actor);
            $this->fail('Treatment persisted despite audit failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('risk_treatments', 0);
            $this->assertSame('inherent_assessed', $cycle->fresh()->status->value);
        }
    }

    public function test_audit_failure_rolls_back_residual_assessment(): void
    {
        [, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $actor);
        $this->failAuditOn('risk_analysis.residual_assessed');
        try {
            app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Rest', $actor, $cycle->fresh()->lock_version);
            $this->fail('Residual assessment persisted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('risk_assessments', 1);
            $this->assertSame('treatment_in_progress', $cycle->fresh()->status->value);
        }
    }

    public function test_audit_failure_rolls_back_external_decision_and_keeps_person_out_of_audit(): void
    {
        [, $cycle, $actor] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'frequent', 'considerable', 'Rest', $actor, $cycle->fresh()->lock_version);
        $this->failAuditOn('risk_analysis.decision_recorded');
        try {
            app(RiskDecisionWorkflow::class)->recordExternal($cycle, RiskDecisionStatus::Accepted, 'SECRET PERSON', 'SECRET ROLE', '2026-10-08', 'SECRET RATIONALE', null, $actor);
            $this->fail('Decision persisted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('risk_decisions', 0);
            $this->assertSame('awaiting_decision', $cycle->fresh()->status->value);
        }
    }

    public function test_audit_failure_rolls_back_cycle_completion(): void
    {
        [, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Rest', $actor, $cycle->fresh()->lock_version);
        $this->failAuditOn('risk_analysis.completed');
        try {
            app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
            $this->fail('Cycle completion persisted.');
        } catch (RuntimeException) {
            $this->assertSame('residual_assessed', $cycle->fresh()->status->value);
            $this->assertNull($cycle->fresh()->completed_at);
        }
    }

    public function test_audit_failure_rolls_back_project_status(): void
    {
        [$project, , $actor] = $this->assessedCycle();
        $actor->update(['role' => 'admin']);
        $this->failAuditOn('project.status_changed');
        try {
            app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Active], $actor);
            $this->fail('Project status persisted.');
        } catch (RuntimeException) {
            $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        }
    }

    public function test_external_decision_audit_contains_only_opaque_context(): void
    {
        [, $cycle, $actor] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'SECRETTREATMENT'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'frequent', 'considerable', 'SECRETRESIDUAL', $actor, $cycle->fresh()->lock_version);
        app(RiskDecisionWorkflow::class)->recordExternal($cycle, RiskDecisionStatus::Accepted, 'SECRETNAME', 'SECRETROLE', '2026-10-08', 'SECRETRATIONALE', null, $actor);
        $context = AuditEvent::query()->where('event_type', 'risk_analysis.decision_recorded')->sole()->context;
        $this->assertEqualsCanonicalizing(['decision_channel', 'decision_id'], array_keys($context));
        $this->assertSame('external', $context['decision_channel']);
        $this->assertNotEmpty($context['decision_id']);
        $context = json_encode($context);
        foreach (['SECRETNAME', 'SECRETROLE', 'SECRETRATIONALE', $actor->email] as $secret) {
            $this->assertStringNotContainsString($secret, $context);
        }
    }

    public function test_internal_decision_audit_distinguishes_recorder_and_decision_maker(): void
    {
        [, $cycle, $actor] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'frequent', 'considerable', 'Rest', $actor, $cycle->fresh()->lock_version);
        $manager = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        app(RiskDecisionWorkflow::class)->decideInternal($cycle, RiskDecisionStatus::Accepted, 'Freigabe', '2026-10-08', $manager);
        $context = AuditEvent::query()->where('event_type', 'risk_analysis.decision_recorded')->sole()->context;
        $this->assertSame($manager->id, $context['decision_maker_id']);
        $this->assertSame($manager->id, $context['recorder_id']);
        $this->assertSame('internal', $context['decision_channel']);
    }

    private function failAuditOn(string $event): void
    {
        $this->app->bind(AuditLogger::class, fn () => new class($event) extends AuditLogger
        {
            public function __construct(private readonly string $failureEvent) {}

            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                if ($eventType === $this->failureEvent) {
                    throw new RuntimeException('Audit unavailable');
                }

                return parent::record($eventType, $actor, $context, $organizationId);
            }
        });
    }
}
