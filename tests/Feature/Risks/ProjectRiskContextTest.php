<?php

namespace Tests\Feature\Risks;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProjectRiskContext;
use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Threats\ProjectRiskContextService;
use App\Services\Threats\ThreatCatalogPublisher;
use Database\Seeders\RiskMethodologySeeder;
use Database\Seeders\ThreatCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ProjectRiskContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_initialization_pins_versions_copies_47_threats_and_is_idempotent(): void
    {
        [$method, $catalog] = $this->seedVersions();
        $project = IsmsProject::factory()->create();
        $service = app(ProjectRiskContextService::class);
        $context = $service->initialize($project, $method, $catalog, $this->actor());

        $this->assertSame($project->id, $context->project_id);
        $this->assertSame($method->id, $context->methodology_version_id);
        $this->assertSame($catalog->id, $context->catalog_version_id);
        $this->assertCount(47, $context->projectThreats);
        $this->assertSame('G 0.1', $context->projectThreats->first()->code);
        $this->assertSame('G 0.47', $context->projectThreats->last()->code);
        $this->assertSame($context->id, $service->initialize($project, $method, $catalog, $this->actor())->id);
        $this->assertSame(47, $context->projectThreats()->count());
        $this->assertSame(1, $context->changes()->count());
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'risk_context.initialized')->count());
    }

    public function test_migration_preserves_old_snapshot_and_records_old_and_new_versions(): void
    {
        [$method, $catalog] = $this->seedVersions();
        $project = IsmsProject::factory()->create();
        $actor = $this->actor();
        $service = app(ProjectRiskContextService::class);
        $context = $service->initialize($project, $method, $catalog, $actor);
        $oldIds = $context->projectThreats()->pluck('id')->all();
        $next = app(ThreatCatalogPublisher::class)->clonePublished($catalog, $this->actor(UserRole::Admin));
        app(ThreatCatalogPublisher::class)->publish($next, $this->actor(UserRole::Admin));
        $service->migrate($context, $method, $next, $actor);

        $this->assertSame($next->id, $context->fresh()->catalog_version_id);
        $this->assertSame(94, $context->projectThreats()->count());
        $this->assertSame($oldIds, $context->projectThreats()->where('catalog_version_id', $catalog->id)->pluck('id')->all());
        $change = $context->changes()->where('action', 'migrated')->sole();
        $this->assertSame($catalog->id, $change->old_catalog_version_id);
        $this->assertSame($next->id, $change->new_catalog_version_id);
        $this->assertSame($method->id, $change->old_methodology_version_id);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'risk_context.migrated')->count());
        $this->assertSame(94, $service->migrate($context, $method, $next, $actor)->projectThreats()->count());
    }

    public function test_context_requires_current_active_internal_actor_and_writable_customer_project(): void
    {
        [$method, $catalog] = $this->seedVersions();
        $project = IsmsProject::factory()->create();
        $actor = $this->actor();
        DB::table('users')->where('id', $actor->id)->update(['role' => UserRole::Consultant->value, 'is_active' => false]);
        foreach ([$actor, User::factory()->for(Organization::factory()->create(['organization_type' => 'customer']))->create(['role' => UserRole::Admin])] as $forbidden) {
            try {
                app(ProjectRiskContextService::class)->initialize($project, $method, $catalog, $forbidden);
                $this->fail('Invalid actor accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, ProjectRiskContext::query()->count());
            }
        }
        $project->update(['status' => 'completed']);
        $this->expectException(ValidationException::class);
        app(ProjectRiskContextService::class)->initialize($project, $method, $catalog, $this->actor());
    }

    public function test_composite_foreign_keys_reject_cross_project_and_cross_catalog_rows(): void
    {
        [$method, $catalog] = $this->seedVersions();
        $a = app(ProjectRiskContextService::class)->initialize(IsmsProject::factory()->create(), $method, $catalog, $this->actor());
        $b = app(ProjectRiskContextService::class)->initialize(IsmsProject::factory()->create(), $method, $catalog, $this->actor());
        $threat = $a->projectThreats()->firstOrFail();
        $custom = [
            'id' => (string) Str::uuid(),
            'project_id' => $a->project_id,
            'project_risk_context_id' => $b->id,
            'catalog_version_id' => $catalog->id,
            'code' => 'ORG-X',
            'title' => 'Local threat',
            'description' => 'Concrete local threat',
            'availability' => true,
            'sort_order' => 48,
            'addition_rationale' => 'Local context',
            'responsible_name' => 'Responsible',
            'created_by' => $this->actor()->id,
        ];
        try {
            DB::transaction(fn () => DB::table('project_threats')->insert($custom));
            $this->fail('A custom threat was attached to a context in another project.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('project_threats', ['id' => $custom['id']]);
        }
        foreach ([
            fn () => DB::table('project_threats')->where('id', $threat->id)->update(['project_id' => $b->project_id]),
            fn () => DB::table('project_threats')->where('id', $threat->id)->update(['project_risk_context_id' => $b->id]),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Cross-project substitution accepted.');
            } catch (QueryException) {
                $this->assertSame($a->project_id, $threat->fresh()->project_id);
            }
        }

        $other = app(ThreatCatalogPublisher::class)->clonePublished($catalog, $this->actor(UserRole::Admin));
        $foreignEntry = $other->entries()->firstOrFail();
        $foreignSnapshot = [
            'id' => (string) Str::uuid(),
            'project_id' => $a->project_id,
            'project_risk_context_id' => $a->id,
            'catalog_version_id' => $catalog->id,
            'threat_catalog_entry_id' => $foreignEntry->id,
            'code' => 'ORG-Y',
            'title' => 'Wrong catalog entry',
            'description' => 'Entry belongs to another version',
            'availability' => true,
            'sort_order' => 49,
            'created_by' => $this->actor()->id,
        ];
        try {
            DB::transaction(fn () => DB::table('project_threats')->insert($foreignSnapshot));
            $this->fail('An entry from another catalog version was copied.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('project_threats', ['id' => $foreignSnapshot['id']]);
        }
        try {
            DB::transaction(fn () => DB::table('project_threats')->where('id', $threat->id)->update(['threat_catalog_entry_id' => $foreignEntry->id]));
            $this->fail('A catalog entry from another version was accepted.');
        } catch (QueryException) {
            $this->assertSame($threat->threat_catalog_entry_id, $threat->fresh()->threat_catalog_entry_id);
        }
    }

    public function test_migration_rejects_older_catalog_version_without_rewriting_history(): void
    {
        [$method, $catalog] = $this->seedVersions();
        $service = app(ProjectRiskContextService::class);
        $actor = $this->actor();
        $context = $service->initialize(IsmsProject::factory()->create(), $method, $catalog, $actor);
        $publisher = app(ThreatCatalogPublisher::class);
        $new = $publisher->clonePublished($catalog, $this->actor(UserRole::Admin));
        $publisher->publish($new, $this->actor(UserRole::Admin));
        $service->migrate($context, $method, $new, $actor);

        try {
            $service->migrate($context, $method, $catalog, $actor);
            $this->fail('A previous catalog version was accepted.');
        } catch (ValidationException) {
            $this->assertSame($new->id, $context->fresh()->catalog_version_id);
            $this->assertSame(94, $context->projectThreats()->count());
            $this->assertSame(2, $context->changes()->count());
        }
    }

    public function test_migration_rechecks_loaded_actor_and_rolls_back_audit_failure(): void
    {
        [$method, $catalog] = $this->seedVersions();
        $actor = $this->actor();
        $context = app(ProjectRiskContextService::class)->initialize(IsmsProject::factory()->create(), $method, $catalog, $actor);
        $publisher = app(ThreatCatalogPublisher::class);
        $new = $publisher->clonePublished($catalog, $this->actor(UserRole::Admin));
        $publisher->publish($new, $this->actor(UserRole::Admin));
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            app(ProjectRiskContextService::class)->migrate($context, $method, $new, $actor);
            $this->fail('A deactivated actor was accepted.');
        } catch (ValidationException) {
            $this->assertSame(47, $context->projectThreats()->count());
        }
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(ProjectRiskContextService::class)->migrate($context, $method, $new, $actor);
            $this->fail('Audit failure should roll back migration.');
        } catch (RuntimeException) {
            $this->assertSame($catalog->id, $context->fresh()->catalog_version_id);
            $this->assertSame(47, $context->projectThreats()->count());
            $this->assertSame(1, $context->changes()->count());
        }
    }

    public function test_initialization_rolls_back_if_audit_fails(): void
    {
        [$method, $catalog] = $this->seedVersions();
        $project = IsmsProject::factory()->create();
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(ProjectRiskContextService::class)->initialize($project, $method, $catalog, $this->actor());
            $this->fail('Audit failure must propagate.');
        } catch (RuntimeException) {
            $this->assertSame(0, ProjectRiskContext::query()->count());
            $this->assertSame(0, DB::table('project_threats')->count());
        }
    }

    public function test_context_workflow_validation_has_stable_german_messages_and_keys(): void
    {
        [$method, $catalog] = $this->seedVersions();
        $service = app(ProjectRiskContextService::class);
        $writer = $this->actor();
        $project = IsmsProject::factory()->create();

        $this->assertValidation(fn () => $service->initialize($project, $method, $catalog, User::factory()->for(Organization::factory()->create(['organization_type' => 'customer']))->create()), 'actor', 'Ein aktiver interner Administrator oder Berater ist erforderlich.');
        $completed = IsmsProject::factory()->create(['status' => 'completed']);
        $this->assertValidation(fn () => $service->initialize($completed, $method, $catalog, $writer), 'project', 'Ein bearbeitbares Kundenprojekt ist erforderlich.');
        $this->assertValidation(fn () => $service->initialize($project, RiskMethodologyVersion::factory()->create(), $catalog, $writer), 'methodology', 'Eine veröffentlichte Risikomethodik ist erforderlich.');
        $this->assertValidation(fn () => $service->initialize($project, $method, ThreatCatalogVersion::factory()->create(), $writer), 'catalog', 'Ein veröffentlichter Gefährdungskatalog ist erforderlich.');

        $context = $service->initialize($project, $method, $catalog, $writer);
        $publisher = app(ThreatCatalogPublisher::class);
        $new = $publisher->clonePublished($catalog, $this->actor(UserRole::Admin));
        $publisher->publish($new, $this->actor(UserRole::Admin));
        $this->assertValidation(fn () => $service->initialize($project, $method, $new, $writer), 'context', 'Für dieses Projekt ist bereits ein anderer Risikokontext initialisiert. Verwenden Sie die Migration.');
        $service->migrate($context, $method, $new, $writer);
        $this->assertValidation(fn () => $service->migrate($context, $method, $catalog, $writer), 'versions', 'Für die Migration sind Versionen derselben Familie erforderlich, die nicht älter als die aktuellen Versionen sind.');
    }

    private function assertValidation(callable $operation, string $key, string $message): void
    {
        try {
            $operation();
            $this->fail('Validation should reject this operation.');
        } catch (ValidationException $exception) {
            $this->assertSame([$key => [$message]], $exception->errors());
        }
    }

    private function seedVersions(): array
    {
        $this->seed([RiskMethodologySeeder::class, ThreatCatalogSeeder::class]);

        return [RiskMethodologyVersion::query()->sole(), ThreatCatalogVersion::query()->sole()];
    }

    private function actor(UserRole $role = UserRole::Consultant): User
    {
        return User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => $role, 'is_active' => true]);
    }
}
