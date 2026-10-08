<?php

namespace Tests\Feature\Risks;

use App\Enums\RiskDecisionStatus;
use App\Enums\RiskTreatmentAction;
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
}
