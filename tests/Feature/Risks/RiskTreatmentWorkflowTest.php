<?php

namespace Tests\Feature\Risks;

use App\Enums\EvidenceReviewStatus;
use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Enums\RiskTreatmentAction;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\Measure;
use App\Services\Evidence\EvidenceReviewService;
use App\Services\Findings\FindingWorkflow;
use App\Services\Measures\MeasureWorkflow;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskCycleCompleter;
use App\Services\Risks\RiskTreatmentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RiskTreatmentWorkflowTest extends TestCase
{
    use InteractsWithRiskTreatment;
    use RefreshDatabase;

    #[DataProvider('nonCreditableMeasures')]
    public function test_non_completed_measures_cannot_reduce_residual_risk(string $status): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $workflow = app(RiskTreatmentWorkflow::class);
        $workflow->select($cycle, RiskTreatmentAction::Reduce, ['rationale' => 'Maßnahmenplan'], $actor);
        $measure = Measure::factory()->for($project, 'project')->create(['status' => $status]);
        $workflow->linkMeasure($cycle, $measure, $actor);

        $this->expectException(ValidationException::class);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Unbegründete Reduktion', $actor, $cycle->fresh()->lock_version);
    }

    public static function nonCreditableMeasures(): array
    {
        return [['planned'], ['in_progress'], ['blocked'], ['cancelled']];
    }

    public function test_completed_measure_allows_matrix_derived_residual_and_cross_project_link_is_rejected(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $workflow = app(RiskTreatmentWorkflow::class);
        $workflow->select($cycle, RiskTreatmentAction::Reduce, ['rationale' => 'Wirksame Maßnahme'], $actor);
        $other = Measure::factory()->create();
        try {
            $workflow->linkMeasure($cycle, $other, $actor);
            $this->fail('Cross-project measure accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('risk_analysis_measure_links', 0);
        }
        $measure = Measure::factory()->for($project, 'project')->create(['status' => MeasureStatus::Completed, 'completed_by' => $actor->id, 'completed_at' => now()]);
        $workflow->linkMeasure($cycle, $measure, $actor);
        $residual = app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Wirksamkeit geprüft', $actor, $cycle->fresh()->lock_version);
        $this->assertSame('low', $residual->riskLevel->key);
        $this->assertSame('residual_assessed', $cycle->fresh()->status->value);
    }

    public function test_transfer_requires_counterparty_and_verified_evidence(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $workflow = app(RiskTreatmentWorkflow::class);
        try {
            $workflow->select($cycle, RiskTreatmentAction::Transfer, ['rationale' => 'Vertrag'], $actor);
            $this->fail('Missing counterparty accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('risk_treatments', 0);
        }
        $workflow->select($cycle, RiskTreatmentAction::Transfer, ['rationale' => 'Vertrag', 'counterparty_name' => 'Geheimer Partner'], $actor);
        $evidence = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Rejected]);
        $workflow->linkEvidence($cycle, $evidence, $actor);
        try {
            app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Vertrag wirkt', $actor, $cycle->fresh()->lock_version);
            $this->fail('Rejected evidence credited.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('risk_assessments', 1);
        }
        $evidence->update(['status' => EvidenceReviewStatus::Verified]);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Vertrag wirkt', $actor, $cycle->fresh()->lock_version);
        $this->assertSame('residual_assessed', $cycle->fresh()->status->value);
    }

    public function test_avoid_needs_verification_statement_and_accept_cannot_reduce(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $workflow = app(RiskTreatmentWorkflow::class);
        try {
            $workflow->select($cycle, RiskTreatmentAction::Avoid, ['rationale' => 'Beendet'], $actor);
            $this->fail('Missing statement accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('risk_treatments', 0);
        }
        $proof = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Verified, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $workflow->select($cycle, RiskTreatmentAction::Avoid, ['rationale' => 'Beendet', 'cessation_statement' => 'Szenario nachweislich beendet', 'cessation_evidence_id' => $proof->id], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Beendigung geprüft', $actor, $cycle->fresh()->lock_version);

        [, $acceptCycle, $acceptActor] = $this->assessedCycle();
        $workflow->select($acceptCycle, RiskTreatmentAction::Accept, ['rationale' => 'Bewusst übernommen'], $acceptActor);
        $this->expectException(ValidationException::class);
        app(RiskAssessmentService::class)->assessResidual($acceptCycle, 'rare', 'limited', 'Unzulässige Senkung', $acceptActor, $acceptCycle->fresh()->lock_version);
    }

    public function test_rejected_transfer_evidence_invalidates_completed_cycle(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        $workflow = app(RiskTreatmentWorkflow::class);
        $workflow->select($cycle, RiskTreatmentAction::Transfer, ['rationale' => 'Vertrag', 'counterparty_name' => 'Geheimer Partner'], $actor);
        $evidence = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Verified, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $workflow->linkEvidence($cycle, $evidence, $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Übertragen', $actor, $cycle->fresh()->lock_version);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
        app(EvidenceReviewService::class)->review($evidence, EvidenceReviewStatus::Rejected, 'Nachweis ungültig', $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
    }

    public function test_link_change_after_residual_invalidates_cycle(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        $workflow = app(RiskTreatmentWorkflow::class);
        $workflow->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Unverändert', $actor, $cycle->fresh()->lock_version);
        $evidence = EvidenceFile::factory()->for($project, 'project')->create();
        $workflow->linkEvidence($cycle, $evidence, $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
        $this->assertFalse($cycle->fresh()->is_current);
    }

    public function test_linked_measure_status_change_invalidates_residual_assessment(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        $workflow = app(RiskTreatmentWorkflow::class);
        $workflow->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $actor);
        $measure = Measure::factory()->for($project, 'project')->create(['status' => MeasureStatus::InProgress]);
        $workflow->linkMeasure($cycle, $measure, $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Unverändert', $actor, $cycle->fresh()->lock_version);
        app(MeasureWorkflow::class)->transition($measure, MeasureStatus::Completed, null, $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
    }

    public function test_linked_finding_status_change_invalidates_residual_assessment(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        $workflow = app(RiskTreatmentWorkflow::class);
        $workflow->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $actor);
        $finding = Finding::factory()->for($project, 'project')->create(['status' => FindingStatus::Proposed]);
        $workflow->linkFinding($cycle, $finding, $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Unverändert', $actor, $cycle->fresh()->lock_version);
        app(FindingWorkflow::class)->decide($finding, FindingStatus::Accepted, null, $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
    }

    public function test_removing_credited_measure_invalidates_residual_assessment(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $workflow = app(RiskTreatmentWorkflow::class);
        $workflow->select($cycle, RiskTreatmentAction::Reduce, ['rationale' => 'Reduzieren'], $actor);
        $measure = Measure::factory()->for($project, 'project')->create(['status' => MeasureStatus::Completed, 'completed_by' => $actor->id, 'completed_at' => now()]);
        $workflow->linkMeasure($cycle, $measure, $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Reduziert', $actor, $cycle->fresh()->lock_version);
        $workflow->unlinkMeasure($cycle, $measure, $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
        $this->assertDatabaseCount('risk_analysis_measure_links', 0);
    }

    public function test_reselecting_treatment_after_rejected_decision_updates_the_single_row(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $workflow = app(RiskTreatmentWorkflow::class);
        $first = $workflow->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Ursprünglich'], $actor);
        $proof = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Verified, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $second = $workflow->select($cycle, RiskTreatmentAction::Avoid, ['rationale' => 'Neue Behandlung', 'cessation_statement' => 'Verifiziert beendet', 'cessation_evidence_id' => $proof->id], $actor);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(RiskTreatmentAction::Avoid, $second->action);
        $this->assertDatabaseCount('risk_treatments', 1);
    }

    public function test_avoid_rejects_missing_rejected_and_foreign_cessation_proof(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $workflow = app(RiskTreatmentWorkflow::class);
        $details = ['rationale' => 'Stilllegung', 'cessation_statement' => 'Betrieb eingestellt'];
        $rejected = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Rejected]);
        $unreviewed = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Verified]);
        $foreign = EvidenceFile::factory()->create(['status' => EvidenceReviewStatus::Verified]);
        foreach ([null, $rejected->id, $unreviewed->id, $foreign->id] as $id) {
            try {
                $workflow->select($cycle, RiskTreatmentAction::Avoid, [...$details, 'cessation_evidence_id' => $id], $actor);
                $this->fail('Unverified cessation proof was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('risk_treatments', 0);
            }
        }
    }

    public function test_rejecting_avoid_cessation_proof_invalidates_completed_cycle(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        $proof = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Verified, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Avoid, [
            'rationale' => 'Stilllegung', 'cessation_statement' => 'Betrieb eingestellt', 'cessation_evidence_id' => $proof->id,
        ], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Verifiziert', $actor, $cycle->fresh()->lock_version);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);

        app(EvidenceReviewService::class)->review($proof, EvidenceReviewStatus::Rejected, 'Nicht mehr gültig', $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
    }

    public function test_avoid_completion_rechecks_revoked_proof(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        $proof = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Verified, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Avoid, [
            'rationale' => 'Stilllegung', 'cessation_statement' => 'Betrieb eingestellt', 'cessation_evidence_id' => $proof->id,
        ], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Verifiziert', $actor, $cycle->fresh()->lock_version);
        $proof->update(['status' => EvidenceReviewStatus::Rejected]);

        $this->expectException(ValidationException::class);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
    }

    public function test_completion_rechecks_live_transfer_evidence(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        $workflow = app(RiskTreatmentWorkflow::class);
        $workflow->select($cycle, RiskTreatmentAction::Transfer, ['rationale' => 'Vertrag', 'counterparty_name' => 'Partner'], $actor);
        $evidence = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Verified]);
        $workflow->linkEvidence($cycle, $evidence, $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Übertragen', $actor, $cycle->fresh()->lock_version);
        $evidence->update(['status' => EvidenceReviewStatus::Rejected]);
        $this->expectException(ValidationException::class);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
    }
}
