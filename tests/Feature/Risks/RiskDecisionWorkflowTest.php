<?php

namespace Tests\Feature\Risks;

use App\Enums\EvidenceReviewStatus;
use App\Enums\RiskDecisionStatus;
use App\Enums\RiskTreatmentAction;
use App\Models\EvidenceFile;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskChangeInvalidator;
use App\Services\Risks\RiskCycleCompleter;
use App\Services\Risks\RiskDecisionWorkflow;
use App\Services\Risks\RiskTreatmentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RiskDecisionWorkflowTest extends TestCase
{
    use InteractsWithRiskTreatment;
    use RefreshDatabase;

    private function residualCycle(string $frequency = 'frequent', string $impact = 'considerable'): array
    {
        [$project, $cycle, $actor] = $this->assessedCycle($frequency, $impact);
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Bewusste Akzeptanz'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, $frequency, $impact, 'Residualbegründung', $actor, $cycle->fresh()->lock_version);

        return [$project, $cycle->fresh(), $actor];
    }

    public function test_low_residual_completes_without_decision_but_high_requires_one(): void
    {
        [, $low, $actor] = $this->residualCycle('rare', 'limited');
        app(RiskCycleCompleter::class)->complete($low, $actor, $low->lock_version);
        $this->assertSame('completed', $low->fresh()->status->value);

        [, $high, $actor] = $this->residualCycle();
        $this->expectException(ValidationException::class);
        app(RiskCycleCompleter::class)->complete($high, $actor, $high->lock_version);
    }

    public function test_internal_decision_rejects_assessor_and_accepts_distinct_active_internal_user(): void
    {
        [, $cycle, $actor] = $this->residualCycle();
        $decisions = app(RiskDecisionWorkflow::class);
        try {
            $decisions->decideInternal($cycle, RiskDecisionStatus::Accepted, 'Freigabe', '2026-10-08', $actor);
            $this->fail('Assessor decided own risk.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('risk_decisions', 0);
        }
        $manager = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        $decision = $decisions->decideInternal($cycle, RiskDecisionStatus::Accepted, 'Managementfreigabe', '2026-10-08', $manager);
        $this->assertSame($manager->id, $decision->internal_decision_maker_id);
        $this->assertSame($manager->id, $decision->recorded_by);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
        $this->assertSame('completed', $cycle->fresh()->status->value);
    }

    public function test_external_decision_keeps_recorder_and_person_separate(): void
    {
        [, $cycle, $actor] = $this->residualCycle();
        $decision = app(RiskDecisionWorkflow::class)->recordExternal($cycle, RiskDecisionStatus::Accepted, 'Kundenperson', 'Geschäftsleitung', '2026-10-08', 'Freigabe', null, $actor);
        $this->assertSame('external', $decision->channel->value);
        $this->assertSame($actor->id, $decision->recorded_by);
        $this->assertNull($decision->internal_decision_maker_id);
        $this->assertSame('Kundenperson', $decision->external_decision_maker_name);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
    }

    public function test_rejection_returns_to_treatment_and_cannot_complete(): void
    {
        [, $cycle, $actor] = $this->residualCycle();
        $manager = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        app(RiskDecisionWorkflow::class)->decideInternal($cycle, RiskDecisionStatus::Rejected, 'Nacharbeit', '2026-10-08', $manager);
        $this->assertSame('treatment_in_progress', $cycle->fresh()->status->value);
        $this->expectException(ValidationException::class);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
    }

    public function test_reassessment_preserves_rejected_decisions_residual_revision(): void
    {
        [, $cycle, $actor] = $this->residualCycle();
        $first = RiskAssessment::query()->where('risk_analysis_cycle_id', $cycle->id)->where('phase', 'residual')->sole();
        $manager = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        $decision = app(RiskDecisionWorkflow::class)->decideInternal($cycle, RiskDecisionStatus::Rejected, 'Nacharbeit', '2026-10-08', $manager);

        $second = app(RiskAssessmentService::class)->assessResidual($cycle, 'frequent', 'considerable', 'Neue Restbewertung', $actor, $cycle->fresh()->lock_version);

        $this->assertDatabaseCount('risk_assessments', 3);
        $this->assertSame($first->id, $decision->fresh()->residual_assessment_id);
        $this->assertSame($first->id, $decision->fresh()->residualAssessment->id);
        $this->assertSame(1, $first->fresh()->revision);
        $this->assertSame(2, $second->revision);
        $this->assertSame('Residualbegründung', $first->fresh()->rationale);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($second->id, $cycle->fresh()->currentResidualAssessment->id);
    }

    public function test_material_change_invalidates_current_management_decision(): void
    {
        [$project, $cycle, $actor, $target] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptanz'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'frequent', 'considerable', 'Rest', $actor, $cycle->fresh()->lock_version);
        $manager = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        $decision = app(RiskDecisionWorkflow::class)->decideInternal($cycle, RiskDecisionStatus::Accepted, 'Freigabe', '2026-10-08', $manager);
        app(RiskChangeInvalidator::class)->invalidateForTarget($project, $target, 'protection_need', $actor);
        $this->assertNotNull($decision->fresh()->invalidated_at);
        $this->assertFalse($cycle->fresh()->is_current);
    }

    public function test_completed_decision_can_be_invalidated_without_changing_its_content(): void
    {
        [$project, $cycle, $actor, $target] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptanz'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'frequent', 'considerable', 'Rest', $actor, $cycle->fresh()->lock_version);
        $manager = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        $decision = app(RiskDecisionWorkflow::class)->decideInternal($cycle, RiskDecisionStatus::Accepted, 'Freigabe', '2026-10-08', $manager);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);

        app(RiskChangeInvalidator::class)->invalidateForTarget($project, $target, 'protection_need', $actor);

        $this->assertNotNull($decision->fresh()->invalidated_at);
        $this->assertSame('Freigabe', $decision->fresh()->rationale);
        $this->assertTrue($cycle->fresh()->needs_review);
    }

    public function test_external_decision_evidence_must_be_verified_in_same_project(): void
    {
        [$project, $cycle, $actor] = $this->residualCycle();
        $decisions = app(RiskDecisionWorkflow::class);
        $foreign = EvidenceFile::factory()->create(['status' => EvidenceReviewStatus::Verified]);
        $rejected = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Rejected]);
        foreach ([$foreign, $rejected] as $evidence) {
            try {
                $decisions->recordExternal($cycle, RiskDecisionStatus::Accepted, 'Person', 'Leitung', '2026-10-08', 'Freigabe', $evidence, $actor);
                $this->fail('Invalid decision evidence accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('risk_decisions', 0);
            }
        }
        $verified = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Verified]);
        $decision = $decisions->recordExternal($cycle, RiskDecisionStatus::Accepted, 'Person', 'Leitung', '2026-10-08', 'Freigabe', $verified, $actor);
        $this->assertDatabaseHas('risk_decision_evidence_links', ['risk_decision_id' => $decision->id, 'project_id' => $project->id, 'evidence_id' => $verified->id]);
    }
}
