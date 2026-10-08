<?php

namespace Tests\Feature\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\ProjectStatus;
use App\Enums\RiskTreatmentAction;
use App\Enums\RiskTriggerType;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\ProjectThreat;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Projects\ProjectWorkflow;
use App\Services\Risks\RiskAnalysisWorkflow;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskChangeInvalidator;
use App\Services\Risks\RiskCycleCompleter;
use App\Services\Risks\RiskTreatmentWorkflow;
use App\Services\Risks\RiskTriggerEvaluator;
use App\Services\Threats\ProjectRiskContextService;
use App\Services\Threats\ProjectThreatService;
use App\Services\Threats\ThreatCatalogPublisher;
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

    public function test_triggerless_analysis_without_current_cycle_blocks_project_completion(): void
    {
        [$project, , $actor, $target] = $this->assessedCycle('rare', 'limited');
        $actor->update(['role' => 'admin']);
        app(RiskChangeInvalidator::class)->invalidateForTarget($project, $target, 'protection_need', $actor);

        $this->expectException(ValidationException::class);
        app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Completed], $actor);
    }

    public function test_current_completed_triggerless_analysis_allows_project_completion(): void
    {
        [$project, $cycle, $actor] = $this->assessedCycle('rare', 'limited');
        $actor->update(['role' => 'admin']);
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Tragbar'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Unverändert', $actor, $cycle->fresh()->lock_version);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);

        app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Completed], $actor);
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_retired_threat_analysis_does_not_block_completed_replacement(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        $actor->update(['role' => 'admin']);
        $threatData = [
            'code' => 'CUSTOM-1', 'title' => 'Standortgefahr', 'description' => 'Lokale Bedrohung',
            'addition_rationale' => 'Projektbezug', 'confidentiality' => false, 'integrity' => false,
            'availability' => true, 'responsible_name' => 'Verantwortlich',
        ];
        $threats = app(ProjectThreatService::class);
        $oldThreat = $threats->createCustom($project, $threatData, $actor);
        $oldCycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $oldThreat, [
            'title' => 'Altszenario', 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'availability' => true, 'confidentiality' => false, 'integrity' => false,
        ], [], $actor);
        app(RiskAssessmentService::class)->assessInherent($oldCycle, 'rare', 'limited', 'Erstbewertung', $actor, 0);
        $replacementThreat = $threats->updateCustom($oldThreat, [...$threatData, 'title' => 'Neue Standortgefahr'], $actor);
        $this->assertFalse($oldThreat->fresh()->is_active);
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $replacementThreat, [
            'title' => 'Ersatzszenario', 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'availability' => true, 'confidentiality' => false, 'integrity' => false,
        ], [], $actor);
        app(RiskAssessmentService::class)->assessInherent($cycle, 'rare', 'limited', 'Erstbewertung', $actor, 0);
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Tragbar'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Unverändert', $actor, $cycle->fresh()->lock_version);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);

        app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Completed], $actor);
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_old_catalog_analysis_does_not_block_completed_current_catalog_analysis(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $actor->update(['role' => 'admin']);
        $oldThreat = $context->projectThreats()->firstOrFail();
        $oldCycle = $this->createCycle($project, $target, $oldThreat, $actor, 'Alter Katalog');
        app(RiskAssessmentService::class)->assessInherent($oldCycle, 'rare', 'limited', 'Erstbewertung', $actor, 0);

        $catalog = $context->catalog_version_id;
        $publisher = app(ThreatCatalogPublisher::class);
        $next = $publisher->clonePublished(ThreatCatalogVersion::query()->findOrFail($catalog), $actor);
        $publisher->publish($next, $actor);
        app(ProjectRiskContextService::class)->migrate($context, RiskMethodologyVersion::query()->findOrFail($context->methodology_version_id), $next, $actor);

        $this->assertTrue($oldThreat->fresh()->is_active);
        $this->assertTrue($oldCycle->fresh()->needs_review);
        $this->assertFalse($oldCycle->fresh()->is_current);
        $newThreat = $context->projectThreats()->where('catalog_version_id', $next->id)->firstOrFail();
        $newCycle = $this->createCycle($project, $target, $newThreat, $actor, 'Aktueller Katalog');
        app(RiskAssessmentService::class)->assessInherent($newCycle, 'rare', 'limited', 'Erstbewertung', $actor, 0);

        try {
            app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Completed], $actor);
            $this->fail('Incomplete current catalog analysis accepted.');
        } catch (ValidationException) {
            $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        }

        $this->completeAcceptCycle($newCycle, $actor);
        app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Completed], $actor);
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_active_custom_threat_without_catalog_replacement_still_blocks_completion(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $actor->update(['role' => 'admin']);
        $custom = app(ProjectThreatService::class)->createCustom($project, [
            'code' => 'CUSTOM-2', 'title' => 'Eigenes Risiko', 'description' => 'Projektbezogene Gefahr',
            'addition_rationale' => 'Zusätzlicher Projektbezug', 'confidentiality' => false, 'integrity' => false,
            'availability' => true, 'responsible_name' => 'Verantwortlich',
        ], $actor);
        $publisher = app(ThreatCatalogPublisher::class);
        $next = $publisher->clonePublished(ThreatCatalogVersion::query()->findOrFail($context->catalog_version_id), $actor);
        $publisher->publish($next, $actor);
        app(ProjectRiskContextService::class)->migrate($context, RiskMethodologyVersion::query()->findOrFail($context->methodology_version_id), $next, $actor);
        $baseline = $context->projectThreats()->where('catalog_version_id', $next->id)->firstOrFail();
        $baselineCycle = $this->createCycle($project, $target, $baseline, $actor, 'Aktueller Katalog');
        app(RiskAssessmentService::class)->assessInherent($baselineCycle, 'rare', 'limited', 'Erstbewertung', $actor, 0);
        $this->completeAcceptCycle($baselineCycle, $actor);

        $customCycle = $this->createCycle($project, $target, $custom, $actor, 'Eigene Gefahr');
        app(RiskAssessmentService::class)->assessInherent($customCycle, 'rare', 'limited', 'Erstbewertung', $actor, 0);
        try {
            app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Completed], $actor);
            $this->fail('Incomplete active custom analysis accepted.');
        } catch (ValidationException) {
            $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        }

        $this->completeAcceptCycle($customCycle, $actor);
        app(ProjectWorkflow::class)->update($project, ['status' => ProjectStatus::Completed], $actor);
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    private function createCycle(IsmsProject $project, DependencyNode $target, ProjectThreat $threat, User $actor, string $title): RiskAnalysisCycle
    {
        return app(RiskAnalysisWorkflow::class)->create($project, $target, $threat, [
            'title' => $title, 'cause' => 'Ursache', 'event' => 'Ereignis', 'impact' => 'Folge',
            'availability' => true, 'confidentiality' => false, 'integrity' => false,
        ], [], $actor);
    }

    private function completeAcceptCycle(RiskAnalysisCycle $cycle, User $actor): void
    {
        app(RiskTreatmentWorkflow::class)->select($cycle, RiskTreatmentAction::Accept, ['rationale' => 'Tragbar'], $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Unverändert', $actor, $cycle->fresh()->lock_version);
        app(RiskCycleCompleter::class)->complete($cycle, $actor, $cycle->fresh()->lock_version);
    }
}
