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
use App\Models\Organization;
use App\Models\ProtectionNeed;
use App\Models\RiskTargetThreatReview;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Findings\FindingWorkflow;
use App\Services\ProtectionNeeds\ProtectionNeedService;
use App\Services\Risks\FindingRiskTargetService;
use App\Services\Risks\RiskTriggerEvaluator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Findings\InteractsWithFindingWorkflow;
use Tests\TestCase;

class RiskTriggerTest extends TestCase
{
    use InteractsWithFindingWorkflow;
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_elevated_effective_need_opens_once_and_lowering_supersedes_without_deleting_history(): void
    {
        [$project, $target] = $this->riskTarget();
        ProtectionNeed::query()->where('project_id', $project->id)->where('business_process_id', $target->id)->where('objective', 'confidentiality')->update(['effective_level' => 'high']);
        $service = app(RiskTriggerEvaluator::class);
        $first = $service->refreshTarget($project, $target)->sole();
        $this->assertSame(RiskTriggerType::ElevatedProtectionNeed, $first->type);
        $this->assertSame(RiskTriggerStatus::Open, $first->status);
        $this->assertSame($first->id, $service->refreshTarget($project, $target)->sole()->id);
        ProtectionNeed::query()->where('business_process_id', $target->id)->update(['effective_level' => 'normal']);
        $service->refreshTarget($project, $target);
        $this->assertSame(RiskTriggerStatus::Superseded, $first->fresh()->status);
        $this->assertSame(0, $service->refreshTarget($project, $target)->count());
    }

    public function test_accepted_link_opens_finding_trigger_and_closure_supersedes_it(): void
    {
        [$customer, $project, , $question, $actor] = $this->findingContext();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $target = DependencyNode::process($process);
        $finding = app(FindingWorkflow::class)->propose($project, $question, $this->findingPayload(), $actor);
        app(FindingRiskTargetService::class)->link($finding, $target, $actor);
        $this->assertDatabaseCount('risk_analysis_triggers', 0);
        app(FindingWorkflow::class)->decide($finding, FindingStatus::Accepted, null, $actor);
        $trigger = app(RiskTriggerEvaluator::class)->refreshTarget($project, $target)->sole();
        $this->assertSame(RiskTriggerType::InsufficientBaselineCoverage, $trigger->type);
        $this->assertSame($finding->id, $trigger->source_finding_id);
        $this->assertSame(RiskTriggerStatus::Open, $trigger->status);
        Measure::factory()->for($project)->for($finding)->create(['status' => 'completed']);
        app(FindingWorkflow::class)->close($finding, $actor);
        $this->assertSame(RiskTriggerStatus::Superseded, $trigger->fresh()->status);
    }

    public function test_manual_trigger_is_dismissible_by_creator_but_nonstandard_is_mandatory(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        $service = app(RiskTriggerEvaluator::class);
        $manual = $service->register($project, $target, RiskTriggerType::Manual, 'Gezielter Hinweis', $actor);
        $mandatory = $service->register($project, $target, RiskTriggerType::NonstandardScenario, 'Abweichendes Szenario', $actor);
        $this->assertSame('process', AuditEvent::query()->where('event_type', 'risk_trigger.opened')->firstOrFail()->context['target_type']);
        $this->assertSame(RiskTriggerStatus::Open, $mandatory->status);
        try {
            $service->dismiss($mandatory, $actor);
            $this->fail('Mandatory trigger was dismissed.');
        } catch (ValidationException) {
            $this->assertSame(RiskTriggerStatus::Open, $mandatory->fresh()->status);
        }
        $service->dismiss($manual, $actor);
        $this->assertSame(RiskTriggerStatus::Superseded, $manual->fresh()->status);
        $newManual = $service->register($project, $target, RiskTriggerType::Manual, 'Gezielter Hinweis', $actor);
        $this->assertNotSame($manual->id, $newManual->id);
        $this->assertSame(RiskTriggerStatus::Open, $newManual->status);
        $this->assertSame(3, AuditEvent::query()->where('event_type', 'risk_trigger.opened')->count());
    }

    public function test_cross_project_link_is_rejected_by_service_and_composite_fk(): void
    {
        [$customer, $project, , $question, $actor] = $this->findingContext();
        $finding = app(FindingWorkflow::class)->propose($project, $question, $this->findingPayload(), $actor);
        $other = IsmsProject::factory()->for(Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $target = DependencyNode::process(BusinessProcess::factory()->for($other, 'project')->create());
        $this->expectException(ValidationException::class);
        app(FindingRiskTargetService::class)->link($finding, $target, $actor);
    }

    public function test_raw_cross_project_finding_link_is_rejected(): void
    {
        [$customer, $project, , $question, $actor] = $this->findingContext();
        $finding = app(FindingWorkflow::class)->propose($project, $question, $this->findingPayload(), $actor);
        $other = IsmsProject::factory()->create();
        $process = BusinessProcess::factory()->for($other, 'project')->create();
        $this->assertTrue(Schema::hasTable('finding_process_links'));
        $this->expectException(QueryException::class);
        DB::table('finding_process_links')->insert(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'finding_id' => $finding->id, 'business_process_id' => $process->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_rate_target_refreshes_elevated_trigger_inside_rating_transaction(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        $needs = ProtectionNeed::query()->where('business_process_id', $target->id)->get();
        $versions = $needs->mapWithKeys(fn (ProtectionNeed $need): array => [$need->objective->value => $need->lock_version])->all();
        app(ProtectionNeedService::class)->rateTarget($project, $target, ['confidentiality' => ['level' => 'high', 'rationale' => 'Kritischer Inhalt']], $actor, $versions);
        $this->assertDatabaseHas('risk_analysis_triggers', ['project_id' => $project->id, 'business_process_id' => $target->id, 'type' => 'elevated_protection_need', 'status' => 'open']);
        $this->assertSame($actor->id, AuditEvent::query()->where('event_type', 'risk_trigger.opened')->sole()->actor_user_id);
    }

    public function test_finding_decision_rechecks_actor_from_database_before_trigger_refresh(): void
    {
        [$customer, $project, , $question, $actor] = $this->findingContext();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $finding = app(FindingWorkflow::class)->propose($project, $question, $this->findingPayload(), $actor);
        app(FindingRiskTargetService::class)->link($finding, DependencyNode::process($process), $actor);
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            app(FindingWorkflow::class)->decide($finding, FindingStatus::Accepted, null, $actor);
            $this->fail('Inactive actor accepted.');
        } catch (ValidationException) {
            $this->assertSame(FindingStatus::Proposed, $finding->fresh()->status);
            $this->assertDatabaseCount('risk_analysis_triggers', 0);
        }
    }

    public function test_trigger_audit_failure_rolls_back_the_originating_rating(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
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
            app(ProtectionNeedService::class)->rateTarget($project, $target, ['confidentiality' => ['level' => 'high', 'rationale' => 'Kritischer Inhalt']], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
            $this->fail('Audit failure should propagate.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('protection_needs', ['business_process_id' => $target->id, 'objective' => 'confidentiality', 'direct_level' => null, 'effective_level' => null]);
            $this->assertDatabaseCount('risk_analysis_triggers', 0);
        }
    }

    public function test_raw_cross_project_trigger_and_review_rows_are_rejected(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $other = IsmsProject::factory()->create();
        $foreignProcess = BusinessProcess::factory()->for($other, 'project')->create();
        foreach (['risk_analysis_triggers' => ['type' => 'manual', 'source_identity' => 'raw', 'rationale' => 'Test', 'status' => 'open', 'created_by' => $actor->id], 'risk_target_threat_reviews' => ['project_threat_id' => $context->projectThreats()->firstOrFail()->id, 'status' => 'unreviewed']] as $table => $extra) {
            try {
                DB::transaction(fn () => DB::table($table)->insert(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'business_process_id' => $foreignProcess->id, ...$extra, 'created_at' => now(), 'updated_at' => now()]));
                $this->fail('Cross-project row accepted.');
            } catch (QueryException) {
                $this->assertDatabaseMissing($table, ['business_process_id' => $foreignProcess->id]);
            }
        }
    }

    public function test_asset_trigger_uses_only_asset_column_and_receives_threat_reviews(): void
    {
        [$project, , $actor] = $this->riskTarget();
        $asset = Asset::factory()->for($project, 'project')->create();
        $trigger = app(RiskTriggerEvaluator::class)->register($project, DependencyNode::asset($asset), RiskTriggerType::NonstandardScenario, 'Besondere Anlage', $actor);
        $this->assertSame($asset->id, $trigger->asset_id);
        $this->assertNull($trigger->business_process_id);
        $this->assertDatabaseCount('risk_target_threat_reviews', 47);
        $this->assertSame(47, RiskTargetThreatReview::query()->where('asset_id', $asset->id)->whereNull('business_process_id')->count());
    }
}
