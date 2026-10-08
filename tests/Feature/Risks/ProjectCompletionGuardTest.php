<?php

namespace Tests\Feature\Risks;

use App\Enums\ProjectStatus;
use App\Enums\RiskTreatmentAction;
use App\Enums\RiskTriggerType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Projects\ProjectWorkflow;
use App\Services\Risks\RiskAnalysisWorkflow;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskChangeInvalidator;
use App\Services\Risks\RiskCycleCompleter;
use App\Services\Risks\RiskTreatmentWorkflow;
use App\Services\Risks\RiskTriggerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectCompletionGuardTest extends TestCase
{
    use InteractsWithRiskTreatment;
    use RefreshDatabase;

    public function test_project_workflow_rechecks_admin_role_from_database(): void
    {
        [$project, , $actor] = $this->assessedCycle();
        $actor->update(['role' => 'consultant']);
        $staleAdmin = User::query()->findOrFail($actor->id);
        $staleAdmin->role = UserRole::Admin;
        $this->expectException(ValidationException::class);
        app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Active], $staleAdmin);
    }

    public function test_project_completion_requires_current_completed_cycle_for_mandatory_trigger(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $actor->update(['role' => 'admin']);
        $trigger = app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Pflichtanalyse', $actor);
        $workflow = app(ProjectWorkflow::class);
        try {
            $workflow->update($project, ['status' => ProjectStatus::Completed], $actor);
            $this->fail('Open trigger accepted.');
        } catch (ValidationException) {
            $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        }

        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), [
            'title' => 'Szenario', 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'availability' => true, 'confidentiality' => false, 'integrity' => false,
        ], [$trigger->id], $actor);
        app(RiskAssessmentService::class)->assessInherent($cycle, 'rare', 'limited', 'Erstbewertung', $actor, 0);
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Tragbar'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Unverändert', $actor, $cycle->fresh()->lock_version);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
        $workflow->update($project, ['status' => ProjectStatus::Completed], $actor);
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_stale_completed_cycle_does_not_cover_project_completion(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $actor->update(['role' => 'admin']);
        $trigger = app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Pflichtanalyse', $actor);
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), [
            'title' => 'Szenario', 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'availability' => true, 'confidentiality' => false, 'integrity' => false,
        ], [$trigger->id], $actor);
        app(RiskAssessmentService::class)->assessInherent($cycle, 'rare', 'limited', 'Erstbewertung', $actor, 0);
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Tragbar'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Unverändert', $actor, $cycle->fresh()->lock_version);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
        app(RiskChangeInvalidator::class)->invalidateForTarget($project, $target, 'protection_need', $actor);
        $this->expectException(ValidationException::class);
        app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Completed], $actor);
    }
}
