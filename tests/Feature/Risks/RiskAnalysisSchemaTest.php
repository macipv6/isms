<?php

namespace Tests\Feature\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\RiskTriggerType;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Services\Risks\RiskAnalysisWorkflow;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskTriggerEvaluator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiskAnalysisSchemaTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_completed_cycle_is_immutable_in_database_except_review_marker(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), ['title' => 'Ausfall', 'cause' => 'Defekt', 'event' => 'Stillstand', 'impact' => 'Lieferverzug', 'availability' => true, 'integrity' => false, 'confidentiality' => false], [], $actor);
        $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        DB::table('risk_analysis_cycles')->where('id', $cycle->id)->update(['needs_review' => true]);
        $this->assertTrue($cycle->fresh()->needs_review);
        $this->expectException(QueryException::class);
        DB::table('risk_analysis_cycles')->where('id', $cycle->id)->update(['impact' => 'Manipuliert']);
    }

    public function test_cycle_numbers_must_advance_one_at_a_time(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), ['title' => 'Ausfall', 'cause' => 'Defekt', 'event' => 'Stillstand', 'impact' => 'Lieferverzug', 'availability' => true, 'integrity' => false, 'confidentiality' => false], [], $actor);
        $this->expectException(QueryException::class);
        DB::table('risk_analysis_cycles')->insert([...$cycle->getAttributes(), 'id' => (string) Str::uuid(), 'cycle_number' => 4, 'is_current' => false]);
    }

    public function test_completed_cycle_review_marker_cannot_be_cleared(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), ['title' => 'Ausfall', 'cause' => 'Defekt', 'event' => 'Stillstand', 'impact' => 'Lieferverzug', 'availability' => true, 'integrity' => false, 'confidentiality' => false], [], $actor);
        $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        DB::table('risk_analysis_cycles')->where('id', $cycle->id)->update(['needs_review' => true]);
        $this->expectException(QueryException::class);
        DB::table('risk_analysis_cycles')->where('id', $cycle->id)->update(['needs_review' => false]);
    }

    public function test_database_rejects_second_current_draft_and_missing_cia(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), ['title' => 'Ausfall', 'cause' => 'Defekt', 'event' => 'Stillstand', 'impact' => 'Lieferverzug', 'availability' => true, 'integrity' => false, 'confidentiality' => false], [], $actor);
        try {
            DB::transaction(fn () => DB::table('risk_analysis_cycles')->insert([...$cycle->getAttributes(), 'id' => (string) Str::uuid(), 'cycle_number' => 2]));
            $this->fail('Second current draft accepted.');
        } catch (QueryException) {
            $this->assertSame(1, $cycle->analysis->cycles()->count());
        }
        $this->expectException(QueryException::class);
        DB::table('risk_analysis_cycles')->where('id', $cycle->id)->update(['availability' => false]);
    }

    public function test_cycle_title_must_match_the_stable_casefolded_identity(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $first = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), ['title' => 'Ausfall', 'cause' => 'Defekt', 'event' => 'Stillstand', 'impact' => 'Lieferverzug', 'availability' => true, 'integrity' => false, 'confidentiality' => false], [], $actor);
        $otherAnalysisId = (string) Str::uuid();
        DB::table('risk_analyses')->insert(['id' => $otherAnalysisId, 'project_id' => $project->id, 'business_process_id' => $target->id, 'project_threat_id' => $first->analysis->project_threat_id, 'scenario_identity' => 'anders', 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('risk_analysis_cycles')->insert([...$first->getAttributes(), 'id' => (string) Str::uuid(), 'risk_analysis_id' => $otherAnalysisId]);
    }

    public function test_completed_assessment_is_database_immutable(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), ['title' => 'Ausfall', 'cause' => 'Defekt', 'event' => 'Stillstand', 'impact' => 'Lieferverzug', 'availability' => true, 'integrity' => false, 'confidentiality' => false], [], $actor);
        $assessment = app(RiskAssessmentService::class)->assessInherent($cycle, 'frequent', 'considerable', 'Bewertung', $actor, 0);
        $cycle->refresh()->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('risk_assessments')->where('id', $assessment->id)->update(['rationale' => 'Manipuliert']);
    }

    public function test_analysis_requires_one_target_and_trigger_link_requires_same_project(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), ['title' => 'Ausfall', 'cause' => 'Defekt', 'event' => 'Stillstand', 'impact' => 'Lieferverzug', 'availability' => true, 'integrity' => false, 'confidentiality' => false], [], $actor);
        $other = IsmsProject::factory()->for(Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $foreignTarget = DependencyNode::process(BusinessProcess::factory()->for($other, 'project')->create());
        $foreignTrigger = app(RiskTriggerEvaluator::class)->register($other, $foreignTarget, RiskTriggerType::Manual, 'Hinweis', $actor);
        $asset = Asset::factory()->for($project, 'project')->create();
        try {
            DB::transaction(fn () => DB::table('risk_analyses')->where('id', $cycle->risk_analysis_id)->update(['asset_id' => $asset->id]));
            $this->fail('Two target columns accepted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('risk_analyses', ['id' => $cycle->risk_analysis_id, 'asset_id' => null]);
        }
        $this->expectException(QueryException::class);
        DB::table('risk_analysis_trigger_links')->insert(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'risk_analysis_cycle_id' => $cycle->id, 'risk_analysis_trigger_id' => $foreignTrigger->id, 'created_at' => now(), 'updated_at' => now()]);
    }
}
