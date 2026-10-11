<?php

namespace Tests\Feature\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\FindingStatus;
use App\Enums\RiskTriggerStatus;
use App\Enums\RiskTriggerType;
use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\RiskAnalysisTrigger;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Findings\FindingWorkflow;
use App\Services\ProtectionNeeds\ProtectionNeedInitializer;
use App\Services\ProtectionNeeds\ProtectionNeedService;
use App\Services\Registers\AssetService;
use App\Services\Registers\BusinessProcessService;
use App\Services\Risks\FindingRiskTargetService;
use App\Services\Risks\RiskTriggerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Findings\InteractsWithFindingWorkflow;
use Tests\TestCase;

class RiskTargetLifecycleTest extends TestCase
{
    use InteractsWithFindingWorkflow;
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_finding_acceptance_and_rejection_keep_historical_link_after_target_deactivation(): void
    {
        foreach (['process', 'asset'] as $type) {
            foreach ([FindingStatus::Accepted, FindingStatus::Rejected] as $decision) {
                [, $project, , $question, $actor] = $this->findingContext();
                $target = $this->target($project, $type);
                $finding = app(FindingWorkflow::class)->propose($project, $question, $this->findingPayload(), $actor);
                app(FindingRiskTargetService::class)->link($finding, $target, $actor);
                $this->setActive($target, false, $actor);

                app(FindingWorkflow::class)->decide($finding, $decision, $decision === FindingStatus::Rejected ? 'Nicht bestätigt' : null, $actor);

                $this->assertSame($decision, $finding->fresh()->status);
                $table = $type === 'process' ? 'finding_process_links' : 'finding_asset_links';
                $this->assertDatabaseHas($table, ['finding_id' => $finding->id]);
                $this->assertSame(0, RiskAnalysisTrigger::query()->where('project_id', $project->id)->where('status', 'open')->count());
            }
        }
    }

    public function test_deactivation_supersedes_finding_trigger_and_allows_closure_for_process_and_asset(): void
    {
        foreach (['process', 'asset'] as $type) {
            [, $project, , $question, $actor] = $this->findingContext();
            $target = $this->target($project, $type);
            $finding = app(FindingWorkflow::class)->propose($project, $question, $this->findingPayload(), $actor);
            app(FindingRiskTargetService::class)->link($finding, $target, $actor);
            app(FindingWorkflow::class)->decide($finding, FindingStatus::Accepted, null, $actor);
            $trigger = RiskAnalysisTrigger::query()->where('source_finding_id', $finding->id)->sole();
            $this->assertSame(RiskTriggerStatus::Open, $trigger->status);

            $this->setActive($target, false, $actor);
            $this->assertSame(RiskTriggerStatus::Superseded, $trigger->fresh()->status);
            Measure::factory()->for($project)->for($finding)->create(['status' => 'completed']);
            app(FindingWorkflow::class)->close($finding, $actor);

            $this->assertSame(FindingStatus::Closed, $finding->fresh()->status);
            $this->assertSame(1, RiskAnalysisTrigger::query()->where('source_finding_id', $finding->id)->count());
        }
    }

    public function test_deactivation_supersedes_elevated_trigger_and_audit_failure_rolls_status_back(): void
    {
        foreach (['process', 'asset'] as $type) {
            [$project, $process, $actor] = $this->riskTarget();
            $target = $type === 'process' ? $process : $this->target($project, 'asset');
            app(ProtectionNeedInitializer::class)->ensureTarget($target);
            app(ProtectionNeedService::class)->rateTarget($project, $target, ['confidentiality' => ['level' => 'high', 'rationale' => 'Kritisch']], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
            $trigger = RiskAnalysisTrigger::query()->where('project_id', $project->id)->where('type', 'elevated_protection_need')->where($type === 'process' ? 'business_process_id' : 'asset_id', $target->id)->sole();
            $this->assertSame(RiskTriggerStatus::Open, $trigger->status);

            $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
            {
                public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
                {
                    if ($eventType === 'risk_trigger.superseded') {
                        throw new RuntimeException('Audit unavailable');
                    }

                    return parent::record($eventType, $actor, $context, $organizationId);
                }
            });
            try {
                $this->setActive($target, false, $actor);
                $this->fail('Trigger audit failure did not abort target deactivation.');
            } catch (RuntimeException) {
                $this->assertTrue($this->model($target)->fresh()->is_active);
                $this->assertSame(RiskTriggerStatus::Open, $trigger->fresh()->status);
            }
            $this->app->forgetInstance(AuditLogger::class);
            $this->app->bind(AuditLogger::class, AuditLogger::class);
            $this->setActive($target, false, $actor);
            $this->assertSame(RiskTriggerStatus::Superseded, $trigger->fresh()->status);
            $this->setActive($target, true, $actor);
            $this->assertSame(RiskTriggerStatus::Open, $trigger->fresh()->status);
        }
    }

    public function test_reactivation_creates_one_new_nonstandard_trigger_per_source_without_reviving_manual_trigger(): void
    {
        foreach (['process', 'asset'] as $type) {
            [$project, $process, $actor] = $this->riskTarget();
            $target = $type === 'process' ? $process : $this->target($project, 'asset');
            $evaluator = app(RiskTriggerEvaluator::class);
            $nonstandard = $evaluator->register($project, $target, RiskTriggerType::NonstandardScenario, 'Begrenzte fachliche Begründung', $actor);
            $manual = $evaluator->register($project, $target, RiskTriggerType::Manual, 'Manuelle Beobachtung', $actor);

            $this->setActive($target, false, $actor);
            $this->assertSame(RiskTriggerStatus::Superseded, $nonstandard->fresh()->status);
            $this->assertSame(RiskTriggerStatus::Superseded, $manual->fresh()->status);
            $this->setActive($target, true, $actor);

            $rows = RiskAnalysisTrigger::query()->where('project_id', $project->id)->where($type === 'process' ? 'business_process_id' : 'asset_id', $target->id)->where('type', RiskTriggerType::NonstandardScenario->value)->get();
            $this->assertCount(2, $rows);
            $this->assertSame(1, $rows->where('status', RiskTriggerStatus::Open)->count());
            $replacement = $rows->where('status', RiskTriggerStatus::Open)->sole();
            $this->assertNotSame($nonstandard->id, $replacement->id);
            $this->assertSame($nonstandard->source_identity, $replacement->source_identity);
            $this->assertSame($nonstandard->rationale, $replacement->rationale);
            $this->assertSame($nonstandard->created_by, $replacement->created_by);
            $this->assertSame(0, RiskAnalysisTrigger::query()->where('project_id', $project->id)->where('type', RiskTriggerType::Manual->value)->where('status', RiskTriggerStatus::Open->value)->count());
            $this->setActive($target, true, $actor);
            $this->assertSame(2, RiskAnalysisTrigger::query()->where('project_id', $project->id)->where('type', RiskTriggerType::NonstandardScenario->value)->count());
            $this->assertStringNotContainsString('Begrenzte fachliche Begründung', AuditEvent::query()->where('organization_id', $project->organization_id)->get()->toJson());
        }
    }

    public function test_nonstandard_reactivation_audit_failure_rolls_target_status_and_new_trigger_back(): void
    {
        foreach (['process', 'asset'] as $type) {
            [$project, $process, $actor] = $this->riskTarget();
            $target = $type === 'process' ? $process : $this->target($project, 'asset');
            $original = app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Spezielle Lage', $actor);
            $this->setActive($target, false, $actor);
            $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
            {
                public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
                {
                    if ($eventType === 'risk_trigger.opened') {
                        throw new RuntimeException('Audit unavailable');
                    }

                    return parent::record($eventType, $actor, $context, $organizationId);
                }
            });
            try {
                $this->setActive($target, true, $actor);
                $this->fail('Audit failure did not abort reactivation.');
            } catch (RuntimeException) {
                $this->assertFalse($this->model($target)->fresh()->is_active);
                $this->assertSame(RiskTriggerStatus::Superseded, $original->fresh()->status);
                $this->assertSame(1, RiskAnalysisTrigger::query()->where('project_id', $project->id)->where('type', RiskTriggerType::NonstandardScenario->value)->count());
            }
            $this->app->forgetInstance(AuditLogger::class);
            $this->app->bind(AuditLogger::class, AuditLogger::class);
        }
    }

    private function target(IsmsProject $project, string $type): DependencyNode
    {
        return $type === 'process'
            ? DependencyNode::process(BusinessProcess::factory()->for($project, 'project')->create())
            : DependencyNode::asset(Asset::factory()->for($project, 'project')->create());
    }

    private function model(DependencyNode $target): BusinessProcess|Asset
    {
        return $target->type->value === 'process'
            ? BusinessProcess::query()->findOrFail($target->id)
            : Asset::query()->findOrFail($target->id);
    }

    private function setActive(DependencyNode $target, bool $active, User $actor): void
    {
        if ($target->type->value === 'process') {
            app(BusinessProcessService::class)->changeStatus(BusinessProcess::query()->findOrFail($target->id), $active, $actor);
        } else {
            app(AssetService::class)->changeStatus(Asset::query()->findOrFail($target->id), $active, $actor);
        }
    }
}
