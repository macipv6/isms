<?php

namespace Tests\Feature\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\RiskTriggerType;
use App\Enums\ThreatReviewStatus;
use App\Enums\UserRole;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RiskMethodologyVersion;
use App\Models\RiskTargetThreatReview;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Risks\RiskTriggerEvaluator;
use App\Services\Threats\ProjectRiskContextService;
use App\Services\Threats\ThreatCatalogPublisher;
use App\Services\Threats\ThreatOverviewService;
use Database\Seeders\RiskMethodologySeeder;
use Database\Seeders\ThreatCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ThreatOverviewTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_mandatory_trigger_initializes_exactly_47_reviews_and_repeat_is_idempotent(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        $evaluator = app(RiskTriggerEvaluator::class);
        $evaluator->register($project, $target, RiskTriggerType::NonstandardScenario, 'Neue Bedrohungslage', $actor);
        $this->assertSame(47, RiskTargetThreatReview::query()->where('project_id', $project->id)->count());
        $evaluator->refreshTarget($project, $target);
        $this->assertSame(47, RiskTargetThreatReview::query()->where('project_id', $project->id)->count());
    }

    public function test_review_requires_rationale_for_not_applicable_and_applicable_is_incomplete_in_task_five(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Neue Bedrohungslage', $actor);
        $review = RiskTargetThreatReview::query()->firstOrFail();
        try {
            app(ThreatOverviewService::class)->review($review, ThreatReviewStatus::NotApplicable, null, $actor);
            $this->fail('Missing rationale accepted.');
        } catch (ValidationException) {
            $this->assertSame(ThreatReviewStatus::Unreviewed, $review->fresh()->status);
        }
        app(ThreatOverviewService::class)->review($review, ThreatReviewStatus::Applicable, null, $actor);
        $this->assertFalse(app(ThreatOverviewService::class)->isComplete($project, $target));
        app(ThreatOverviewService::class)->review($review, ThreatReviewStatus::NotApplicable, 'Keine Exposition am Ziel', $actor);
        $this->assertSame('Keine Exposition am Ziel', $review->fresh()->rationale);
    }

    public function test_catalog_migration_adds_47_new_unreviewed_rows_and_preserves_old_reviews(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Sonderszenario', $actor);
        $old = RiskTargetThreatReview::query()->firstOrFail();
        app(ThreatOverviewService::class)->review($old, ThreatReviewStatus::NotApplicable, 'Keine Berührung', $actor);
        $admin = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Admin]);
        $next = app(ThreatCatalogPublisher::class)->clonePublished(ThreatCatalogVersion::query()->findOrFail($context->catalog_version_id), $admin);
        app(ThreatCatalogPublisher::class)->publish($next, $admin);
        app(ProjectRiskContextService::class)->migrate($context, RiskMethodologyVersion::query()->findOrFail($context->methodology_version_id), $next, $actor);
        $this->assertSame(94, RiskTargetThreatReview::query()->where('project_id', $project->id)->count());
        $this->assertSame(ThreatReviewStatus::NotApplicable, $old->fresh()->status);
        $this->assertSame(47, RiskTargetThreatReview::query()->where('status', 'unreviewed')->whereHas('threat', fn ($query) => $query->where('catalog_version_id', $next->id))->count());
        $this->expectException(ValidationException::class);
        app(ThreatOverviewService::class)->review($old, ThreatReviewStatus::Applicable, null, $actor);
    }

    public function test_context_initialization_backfills_reviews_for_existing_mandatory_trigger(): void
    {
        $this->seed([RiskMethodologySeeder::class, ThreatCatalogSeeder::class]);
        $project = IsmsProject::factory()->for(Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $target = DependencyNode::process(BusinessProcess::factory()->for($project, 'project')->create());
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create();
        app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Sonderszenario', $actor);
        $this->assertSame(0, RiskTargetThreatReview::query()->count());
        app(ProjectRiskContextService::class)->initialize($project, RiskMethodologyVersion::query()->sole(), ThreatCatalogVersion::query()->sole(), $actor);
        $this->assertSame(47, RiskTargetThreatReview::query()->where('project_id', $project->id)->count());
    }
}
