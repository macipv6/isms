<?php

namespace Tests\Feature\Risks;

use App\Enums\EvidenceReviewStatus;
use App\Enums\RiskDecisionStatus;
use App\Enums\RiskTreatmentAction;
use App\Models\EvidenceFile;
use App\Models\Measure;
use App\Models\User;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskCycleCompleter;
use App\Services\Risks\RiskDecisionWorkflow;
use App\Services\Risks\RiskTreatmentWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiskTreatmentSchemaTest extends TestCase
{
    use InteractsWithRiskTreatment;
    use RefreshDatabase;

    public function test_database_rejects_transfer_without_counterparty_and_avoid_without_statement(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        foreach (['transfer', 'avoid'] as $action) {
            try {
                DB::transaction(fn () => DB::table('risk_treatments')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $project->id, 'risk_analysis_cycle_id' => $cycle->id,
                    'action' => $action, 'rationale' => 'Valid rationale', 'selected_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail('Missing action prerequisite persisted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('risk_treatments', 0);
            }
        }
    }

    public function test_composite_foreign_key_rejects_cross_project_measure(): void
    {
        [, $cycle, $actor] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Reduce, ['rationale' => 'Reduktion'], $actor);
        $foreign = Measure::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('risk_analysis_measure_links')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $cycle->project_id, 'risk_analysis_cycle_id' => $cycle->id,
            'measure_id' => $foreign->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_external_decision_without_named_person(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $this->expectException(QueryException::class);
        DB::table('risk_decisions')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'risk_analysis_cycle_id' => $cycle->id,
            'status' => 'accepted', 'channel' => 'external', 'rationale' => 'Freigabe', 'decision_date' => '2026-10-08',
            'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_decision_cannot_reference_inherent_assessment_as_residual(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $inherent = $cycle->assessments()->where('phase', 'inherent')->sole();
        $this->expectException(QueryException::class);
        DB::table('risk_decisions')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'risk_analysis_cycle_id' => $cycle->id,
            'residual_assessment_id' => $inherent->id, 'assessment_phase' => 'residual',
            'status' => 'accepted', 'channel' => 'internal', 'rationale' => 'Freigabe', 'decision_date' => '2026-10-08',
            'recorded_by' => $actor->id, 'internal_decision_maker_id' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_decision_cannot_reference_another_projects_residual_assessment(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        [, $foreignCycle, $foreignActor] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($foreignCycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $foreignActor);
        $foreign = app(RiskAssessmentService::class)->assessResidual($foreignCycle, 'frequent', 'considerable', 'Rest', $foreignActor, $foreignCycle->fresh()->lock_version);
        $this->expectException(QueryException::class);
        DB::table('risk_decisions')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'risk_analysis_cycle_id' => $cycle->id,
            'residual_assessment_id' => $foreign->id, 'assessment_phase' => 'residual',
            'status' => 'accepted', 'channel' => 'internal', 'rationale' => 'Freigabe', 'decision_date' => '2026-10-08',
            'recorded_by' => $actor->id, 'internal_decision_maker_id' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_completed_decision_content_is_immutable_in_database(): void
    {
        [, $cycle, $actor] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'frequent', 'considerable', 'Rest', $actor, $cycle->fresh()->lock_version);
        $manager = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        $decision = app(RiskDecisionWorkflow::class)->decideInternal($cycle, RiskDecisionStatus::Accepted, 'Freigabe', '2026-10-08', $manager);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
        $this->expectException(QueryException::class);
        DB::table('risk_decisions')->where('id', $decision->id)->update(['rationale' => 'Manipuliert']);
    }

    public function test_rejected_decision_and_residual_assessment_content_are_immutable(): void
    {
        [, $cycle, $actor] = $this->assessedCycle();
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Akzeptiert'], $actor);
        $assessment = app(RiskAssessmentService::class)->assessResidual($cycle, 'frequent', 'considerable', 'Rest', $actor, $cycle->fresh()->lock_version);
        $manager = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        $decision = app(RiskDecisionWorkflow::class)->decideInternal($cycle, RiskDecisionStatus::Rejected, 'Nacharbeit', '2026-10-08', $manager);
        foreach ([
            fn () => DB::table('risk_decisions')->where('id', $decision->id)->update(['rationale' => 'Manipuliert']),
            fn () => DB::table('risk_assessments')->where('id', $assessment->id)->update(['rationale' => 'Manipuliert']),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Historical content was mutable.');
            } catch (QueryException) {
                $this->assertSame('Nacharbeit', $decision->fresh()->rationale);
                $this->assertSame('Rest', $assessment->fresh()->rationale);
            }
        }
    }

    public function test_avoid_requires_verified_same_project_proof_at_database_boundary(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle();
        $rejected = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Rejected]);
        $unreviewed = EvidenceFile::factory()->for($project, 'project')->create(['status' => EvidenceReviewStatus::Verified]);
        $foreign = EvidenceFile::factory()->create(['status' => EvidenceReviewStatus::Verified]);
        foreach ([$rejected, $unreviewed, $foreign] as $proof) {
            try {
                DB::transaction(fn () => DB::table('risk_treatments')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $project->id, 'risk_analysis_cycle_id' => $cycle->id,
                    'action' => 'avoid', 'rationale' => 'Stilllegung', 'cessation_statement' => 'Beendet',
                    'cessation_evidence_id' => $proof->id, 'selected_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail('Invalid cessation proof persisted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('risk_treatments', 0);
            }
        }
    }
}
