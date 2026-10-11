<?php

namespace Tests\Feature\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\RiskTriggerType;
use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\BusinessProcess;
use App\Models\ProtectionNeed;
use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Dependencies\DependencyService;
use App\Services\ProtectionNeeds\ProtectionNeedService;
use App\Services\RiskMethodology\RiskMethodologyPublisher;
use App\Services\Risks\RiskAnalysisWorkflow;
use App\Services\Risks\RiskChangeInvalidator;
use App\Services\Risks\RiskTriggerEvaluator;
use App\Services\Threats\ProjectRiskContextService;
use App\Services\Threats\ProjectThreatService;
use App\Services\Threats\ThreatCatalogPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RiskInvalidationTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_invalidation_keeps_completed_cycle_and_allows_next_number(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $workflow = app(RiskAnalysisWorkflow::class);
        $cycle = $workflow->create($project, $target, $context->projectThreats()->firstOrFail(), ['title' => 'Ausfall', 'cause' => 'Defekt', 'event' => 'Stillstand', 'impact' => 'Lieferverzug', 'availability' => true, 'integrity' => false, 'confidentiality' => false], [], $actor);
        $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        app(RiskChangeInvalidator::class)->invalidateForTarget($project, $target, 'topology');
        $this->assertTrue($cycle->fresh()->needs_review);
        $this->assertSame(2, $workflow->startNextCycle($cycle->analysis, $actor)->cycle_number);
    }

    public function test_effective_rating_invalidates_a_covered_cycle_in_the_same_command(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $trigger = app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Spezialfall', $actor);
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [$trigger->id], $actor);
        $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        $trigger->update(['status' => 'covered']);
        app(ProtectionNeedService::class)->rateTarget($project, $target, ['availability' => ['level' => 'high', 'rationale' => 'Betriebsabhängigkeit']], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        $this->assertTrue($cycle->fresh()->needs_review);
        $this->assertSame('open', $trigger->fresh()->status->value);
        $this->assertSame($actor->id, AuditEvent::query()->where('event_type', 'risk_analysis.invalidated')->sole()->actor_user_id);
    }

    public function test_dependency_topology_invalidates_completed_target_cycle(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [], $actor);
        $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        $source = BusinessProcess::factory()->for($project, 'project')->create();
        app(DependencyService::class)->create($project, ['source_type' => 'process', 'source_key' => $source->key, 'target_type' => 'process', 'target_key' => $target->key, 'importance' => 'supporting', 'reason' => null], $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
    }

    public function test_tied_provenance_change_invalidates_even_when_effective_level_stays_high(): void
    {
        [$project, , $actor, $context] = $this->riskTarget();
        $a = BusinessProcess::factory()->for($project, 'project')->create();
        $b = BusinessProcess::factory()->for($project, 'project')->create();
        $asset = Asset::factory()->for($project, 'project')->create();
        $target = DependencyNode::asset($asset);
        $dependency = app(DependencyService::class);
        foreach ([$a, $b] as $source) {
            $dependency->create($project, ['source_type' => 'process', 'source_key' => $source->key, 'target_type' => 'asset', 'target_key' => $asset->key, 'importance' => 'supporting', 'reason' => null], $actor);
            app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::process($source), ['availability' => ['level' => 'high', 'rationale' => 'Kritische Versorgung']], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        }
        $need = ProtectionNeed::query()->where('asset_id', $asset->id)->where('objective', 'availability')->firstOrFail();
        $this->assertSame(2, $need->sources()->count());
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [], $actor);
        $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        $sourceNeed = ProtectionNeed::query()->where('business_process_id', $a->id)->where('objective', 'availability')->firstOrFail();
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::process($a), ['availability' => ['level' => 'normal', 'rationale' => 'Reduzierte Abhängigkeit']], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => $sourceNeed->lock_version]);
        $this->assertSame('high', $need->fresh()->effective_level->value);
        $this->assertSame(1, $need->sources()->count());
        $this->assertTrue($cycle->fresh()->needs_review);
    }

    public function test_context_methodology_migration_invalidates_completed_cycle(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), $this->scenario(), [], $actor);
        $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        $old = RiskMethodologyVersion::query()->findOrFail($context->methodology_version_id);
        $admin = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        $next = app(RiskMethodologyPublisher::class)->clonePublished($old, 'Neue Fassung', $admin);
        app(RiskMethodologyPublisher::class)->publish($next, $admin);
        app(ProjectRiskContextService::class)->migrate($context, $next, ThreatCatalogVersion::query()->findOrFail($context->catalog_version_id), $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
    }

    public function test_custom_threat_replacement_invalidates_its_cycle(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        $service = app(ProjectThreatService::class);
        $fields = ['code' => 'LOCAL-1', 'title' => 'Lokale Gefahr', 'description' => 'Konkrete Gefahr', 'addition_rationale' => 'Zusätzliche Gefahr', 'confidentiality' => true, 'integrity' => false, 'availability' => false, 'responsible_name' => 'Verantwortung', 'responsible_email' => null];
        $threat = $service->createCustom($project, $fields, $actor);
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $threat, $this->scenario(), [], $actor);
        $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        $replacement = $service->updateCustom($threat, [...$fields, 'title' => 'Korrigierte Gefahr'], $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
        try {
            app(RiskAnalysisWorkflow::class)->startNextCycle($cycle->analysis, $actor);
            $this->fail('Retired threat accepted for a new cycle.');
        } catch (ValidationException) {
            $next = app(RiskAnalysisWorkflow::class)->create($project, $target, $replacement, $this->scenario(), [], $actor);
            $this->assertSame(1, $next->cycle_number);
            $this->assertNotSame($cycle->risk_analysis_id, $next->risk_analysis_id);
        }
    }

    public function test_catalog_migration_invalidates_cycle_and_keeps_custom_threat_identity(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $fields = ['code' => 'LOCAL-2', 'title' => 'Lokale Gefahr', 'description' => 'Konkrete Gefahr', 'addition_rationale' => 'Zusätzliche Gefahr', 'confidentiality' => true, 'integrity' => false, 'availability' => false, 'responsible_name' => 'Verantwortung', 'responsible_email' => null];
        $threat = app(ProjectThreatService::class)->createCustom($project, $fields, $actor);
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $threat, $this->scenario(), [], $actor);
        $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        $admin = User::factory()->for($actor->organization)->create(['role' => 'admin']);
        $publisher = app(ThreatCatalogPublisher::class);
        $nextCatalog = $publisher->clonePublished(ThreatCatalogVersion::query()->findOrFail($context->catalog_version_id), $admin);
        $publisher->publish($nextCatalog, $admin);
        app(ProjectRiskContextService::class)->migrate($context, RiskMethodologyVersion::query()->findOrFail($context->methodology_version_id), $nextCatalog, $actor);
        $this->assertTrue($cycle->fresh()->needs_review);
        $this->assertSame(2, app(RiskAnalysisWorkflow::class)->startNextCycle($cycle->analysis, $actor)->cycle_number);
    }

    public function test_covered_trigger_stays_covered_until_its_last_current_completed_cycle_is_stale(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $trigger = app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Mehrfachdeckung', $actor);
        $threats = $context->projectThreats()->take(2)->get();
        $first = app(RiskAnalysisWorkflow::class)->create($project, $target, $threats[0], $this->scenario(), [$trigger->id], $actor);
        $second = app(RiskAnalysisWorkflow::class)->create($project, $target, $threats[1], $this->scenario(), [$trigger->id], $actor);
        foreach ([$first, $second] as $cycle) {
            $cycle->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
        }
        $trigger->update(['status' => 'covered']);
        $invalidator = app(RiskChangeInvalidator::class);
        $invalidator->invalidateForThreat($project, $threats[0]->id, 'threat_replacement', $actor);
        $this->assertSame('covered', $trigger->fresh()->status->value);
        $invalidator->invalidateForThreat($project, $threats[1]->id, 'threat_replacement', $actor);
        $this->assertSame('open', $trigger->fresh()->status->value);
    }

    private function scenario(): array
    {
        return ['title' => 'Ausfall', 'cause' => 'Defekt', 'event' => 'Stillstand', 'impact' => 'Lieferverzug', 'availability' => true, 'integrity' => false, 'confidentiality' => false];
    }
}
