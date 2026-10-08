<?php

namespace Tests\Feature\Risks;

use App\Enums\RiskTriggerType;
use App\Enums\ThreatReviewStatus;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RiskMethodologyVersion;
use App\Models\RiskTargetThreatReview;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Risks\RiskTriggerEvaluator;
use App\Services\Threats\ProjectRiskContextService;
use App\Services\Threats\ProjectThreatService;
use App\Services\Threats\ThreatCatalogPublisher;
use App\Services\Threats\ThreatOverviewService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectThreatWorkflowTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_custom_threat_requires_details_and_adds_review_to_mandatory_target(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Sonderszenario', $actor);
        try {
            app(ProjectThreatService::class)->createCustom($project, ['code' => 'ORG-1'], $actor);
            $this->fail('Incomplete custom threat accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('Dieses Feld ist erforderlich.', $exception->errors()['title'][0]);
            $this->assertSame(47, RiskTargetThreatReview::query()->count());
        }
        $threat = app(ProjectThreatService::class)->createCustom($project, $this->payload(), $actor);
        $this->assertSame(48, RiskTargetThreatReview::query()->count());
        $this->assertSame(1, RiskTargetThreatReview::query()->where('project_threat_id', $threat->id)->count());
    }

    public function test_referenced_custom_threat_update_retires_and_replaces_while_preserving_review(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        $service = app(ProjectThreatService::class);
        $threat = $service->createCustom($project, $this->payload(), $actor);
        app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Sonderszenario', $actor);
        $replacement = $service->updateCustom($threat, [...$this->payload(), 'title' => 'Korrigiert'], $actor);
        $this->assertNotSame($threat->id, $replacement->id);
        $this->assertFalse($threat->fresh()->is_active);
        $this->assertSame(48, RiskTargetThreatReview::query()->where('project_id', $project->id)->whereHas('threat', fn ($query) => $query->where('is_active', true))->count());
        $this->assertSame(1, RiskTargetThreatReview::query()->where('project_threat_id', $threat->id)->count());
    }

    public function test_custom_threat_remains_reviewable_after_catalog_migration(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $threat = app(ProjectThreatService::class)->createCustom($project, $this->payload(), $actor);
        app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Sonderszenario', $actor);
        $review = RiskTargetThreatReview::query()->where('project_threat_id', $threat->id)->sole();
        $admin = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Admin]);
        $next = app(ThreatCatalogPublisher::class)->clonePublished(ThreatCatalogVersion::query()->findOrFail($context->catalog_version_id), $admin);
        app(ThreatCatalogPublisher::class)->publish($next, $admin);
        app(ProjectRiskContextService::class)->migrate($context, RiskMethodologyVersion::query()->findOrFail($context->methodology_version_id), $next, $actor);
        $this->assertSame(95, RiskTargetThreatReview::query()->where('project_id', $project->id)->count());
        $this->assertSame(ThreatReviewStatus::NotApplicable, app(ThreatOverviewService::class)->review($review, ThreatReviewStatus::NotApplicable, 'Keine Exposition', $actor)->status);
        $this->assertNotSame($threat->id, app(ProjectThreatService::class)->updateCustom($threat, [...$this->payload(), 'title' => 'Korrigiert'], $actor)->id);
    }

    public function test_custom_threat_rejects_missing_cia_invalid_email_and_baseline_update(): void
    {
        [$project, , $actor, $context] = $this->riskTarget();
        foreach ([['confidentiality' => false], ['responsible_email' => 'not-an-email']] as $bad) {
            try {
                app(ProjectThreatService::class)->createCustom($project, [...$this->payload(), ...$bad], $actor);
                $this->fail('Invalid threat accepted.');
            } catch (ValidationException) {
                $this->assertSame(47, $context->projectThreats()->count());
            }
        }
        $this->expectException(ValidationException::class);
        app(ProjectThreatService::class)->updateCustom($context->projectThreats()->firstOrFail(), $this->payload(), $actor);
    }

    public function test_migration_down_and_up_preserve_retired_custom_threat_with_reused_code(): void
    {
        [$project, $target, $actor] = $this->riskTarget();
        $service = app(ProjectThreatService::class);
        $original = $service->createCustom($project, $this->payload(), $actor);
        app(RiskTriggerEvaluator::class)->register($project, $target, RiskTriggerType::NonstandardScenario, 'Sonderszenario', $actor);
        $replacement = $service->updateCustom($original, [...$this->payload(), 'title' => 'Korrigiert'], $actor);
        $this->assertSame($original->code, $replacement->code);
        $this->assertSame(2, DB::table('project_threats')->where('code', $original->code)->count());

        $analysisMigration = require database_path('migrations/2026_09_22_064000_create_risk_analysis_tables.php');
        $treatmentMigration = require database_path('migrations/2026_09_22_065000_create_risk_treatment_and_decision_tables.php');
        $migration = require database_path('migrations/2026_09_22_063000_create_risk_trigger_and_threat_review_tables.php');
        $treatmentMigration->down();
        $analysisMigration->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('risk_analysis_triggers'));
        $this->assertDatabaseHas('project_threats', ['id' => $original->id, 'is_active' => false, 'code' => $original->code]);
        $this->assertDatabaseHas('project_threats', ['id' => $replacement->id, 'is_active' => true, 'code' => $replacement->code]);
        try {
            DB::transaction(fn () => DB::table('project_threats')->insert([...$replacement->getAttributes(), 'id' => (string) Str::uuid()]));
            $this->fail('A second active custom threat with the same code was accepted.');
        } catch (QueryException) {
            $this->assertSame(2, DB::table('project_threats')->where('code', $original->code)->count());
        }
        $migration->up();
        $analysisMigration->up();
        $treatmentMigration->up();
        $this->assertTrue(Schema::hasTable('risk_analysis_triggers'));
        $this->assertSame(2, DB::table('project_threats')->where('code', $original->code)->count());
    }

    private function payload(): array
    {
        return ['code' => 'ORG-1', 'title' => 'Standortspezifisch', 'description' => 'Konkrete Bedrohung des Betriebs', 'addition_rationale' => 'Nicht im Basiskatalog', 'confidentiality' => true, 'integrity' => false, 'availability' => false, 'responsible_name' => 'Verantwortliche Person', 'responsible_email' => 'person@example.org'];
    }
}
