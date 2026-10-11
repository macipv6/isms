<?php

namespace Tests\Feature\Risks;

use App\Enums\PublicationStatus;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\RiskMethodologyVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\RiskMethodology\RiskMatrix;
use App\Services\RiskMethodology\RiskMethodologyPublisher;
use Database\Seeders\RiskMethodologySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class RiskMethodologyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_draft_can_be_published_and_audits_only_ids(): void
    {
        $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $actor = $this->admin();

        $published = app(RiskMethodologyPublisher::class)->publish($version, $actor);

        $this->assertSame(PublicationStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertSame($actor->id, $published->published_by);
        $event = AuditEvent::query()->where('event_type', 'risk_methodology.published')->sole();
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame(['source_id' => $version->methodology_id, 'target_id' => $version->id], $event->context);
    }

    public function test_missing_cell_cannot_be_published(): void
    {
        $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $version->matrixCells()->firstOrFail()->delete();

        $this->expectException(ValidationException::class);
        app(RiskMethodologyPublisher::class)->publish($version, $this->admin());
    }

    public function test_duplicate_level_key_or_order_is_rejected_before_draft_replacement(): void
    {
        foreach (['key', 'sort_order'] as $field) {
            $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
            $levels = $this->levels($version);
            $levels['frequency'][1][$field] = $levels['frequency'][0][$field];

            try {
                app(RiskMethodologyPublisher::class)->replaceDraftMatrix($version, $levels, $this->cells($version), $this->admin());
                $this->fail('Duplicate level keys and orders must be rejected.');
            } catch (ValidationException) {
                $this->assertCount(16, $version->matrixCells);
            }
        }
    }

    public function test_only_active_internal_admin_may_publish(): void
    {
        $actors = [
            User::factory()->create(['role' => UserRole::Consultant]),
            User::factory()->create(['role' => UserRole::Admin, 'is_active' => false]),
            User::factory()->for(Organization::factory()->create(['organization_type' => 'customer']))->create(['role' => UserRole::Admin]),
        ];
        foreach ($actors as $actor) {
            $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
            try {
                app(RiskMethodologyPublisher::class)->publish($version, $actor);
                $this->fail('Only an active internal administrator can publish.');
            } catch (ValidationException) {
                $this->assertSame(PublicationStatus::Draft, $version->fresh()->status);
            }
        }
    }

    public function test_publish_rechecks_a_loaded_admin_after_database_deactivation(): void
    {
        $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $actor = $this->admin();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        app(RiskMethodologyPublisher::class)->publish($version, $actor);
    }

    public function test_clone_rechecks_a_loaded_admin_after_database_role_change(): void
    {
        $this->seed(RiskMethodologySeeder::class);
        $source = RiskMethodologyVersion::query()->sole();
        $actor = $this->admin();
        DB::table('users')->where('id', $actor->id)->update(['role' => UserRole::Consultant->value]);

        $this->expectException(ValidationException::class);
        app(RiskMethodologyPublisher::class)->clonePublished($source, 'Adaptation', $actor);
    }

    public function test_draft_replacement_rechecks_a_loaded_admin_after_database_deactivation(): void
    {
        $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $actor = $this->admin();
        $levels = $this->levels($version);
        $cells = $this->cells($version);
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        app(RiskMethodologyPublisher::class)->replaceDraftMatrix($version, $levels, $cells, $actor);
    }

    public function test_published_version_and_children_reject_raw_mutation(): void
    {
        $this->seed(RiskMethodologySeeder::class);
        $version = RiskMethodologyVersion::query()->sole();
        $frequency = $version->frequencies()->firstOrFail();

        foreach ([
            fn () => DB::table('risk_frequency_levels')->where('id', $frequency->id)->update(['label' => 'Changed']),
            fn () => DB::table('risk_frequency_levels')->where('id', $frequency->id)->delete(),
            fn () => DB::table('risk_frequency_levels')->insert(['id' => Str::uuid(), 'version_id' => $version->id, 'key' => 'extra', 'label' => 'Extra', 'sort_order' => 5]),
            fn () => DB::table('risk_methodology_versions')->where('id', $version->id)->update(['description' => 'Changed']),
            fn () => DB::table('risk_methodology_versions')->where('id', $version->id)->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Published row mutation should fail.');
            } catch (QueryException) {
                $this->assertSame('Selten', $frequency->fresh()->label);
                $this->assertNotNull($version->fresh());
            }
        }
    }

    public function test_direct_child_update_waits_for_a_concurrent_publication_lock(): void
    {
        config(['database.connections.pgsql_concurrent' => config('database.connections.pgsql')]);
        $other = DB::connection('pgsql_concurrent');
        $methodologyId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $levelId = (string) Str::uuid();
        $other->table('risk_methodologies')->insert(['id' => $methodologyId, 'key' => 'concurrent-test', 'name' => 'Concurrent test']);
        $other->table('risk_methodology_versions')->insert([
            'id' => $versionId, 'methodology_id' => $methodologyId, 'version' => 1,
            'status' => 'draft', 'description' => 'Concurrent test',
        ]);
        $other->table('risk_frequency_levels')->insert([
            'id' => $levelId, 'version_id' => $versionId, 'key' => 'rare', 'label' => 'Rare', 'sort_order' => 1,
        ]);

        DB::beginTransaction();
        try {
            $this->assertNotNull(DB::table('risk_methodology_versions')->where('id', $versionId)->lockForUpdate()->first());
            $other->statement("SET lock_timeout = '150ms'");
            try {
                $other->transaction(fn () => $other->table('risk_frequency_levels')->where('id', $levelId)->update(['label' => 'Changed']));
                $this->fail('A child update must wait for the version lock.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('lock timeout', $exception->getMessage());
            }
        } finally {
            DB::rollBack();
            $other->table('risk_frequency_levels')->where('id', $levelId)->delete();
            $other->table('risk_methodology_versions')->where('id', $versionId)->delete();
            $other->table('risk_methodologies')->where('id', $methodologyId)->delete();
            DB::purge('pgsql_concurrent');
        }
    }

    public function test_cloning_creates_a_new_draft_with_copied_matrix(): void
    {
        $this->seed(RiskMethodologySeeder::class);
        $source = RiskMethodologyVersion::query()->sole();
        $clone = app(RiskMethodologyPublisher::class)->clonePublished($source, 'Confidential adaptation', $this->admin());

        $this->assertSame(2, $clone->version);
        $this->assertSame(PublicationStatus::Draft, $clone->status);
        $this->assertCount(16, $clone->matrixCells);
        $this->assertNotSame($source->frequencies()->firstOrFail()->id, $clone->frequencies()->firstOrFail()->id);
        $this->assertSame('high', app(RiskMatrix::class)->category($clone, 'frequent', 'limited')->key);
        $this->assertSame($source->source_reference, $clone->source_reference);
    }

    public function test_replacing_draft_matrix_audits_field_names_without_descriptions(): void
    {
        $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $levels = $this->levels($version);
        $levels['impact'][0]['description'] = 'Secret impact description';
        $cells = $this->cells($version);
        $cells[0]['risk_key'] = 'high';

        $changed = app(RiskMethodologyPublisher::class)->replaceDraftMatrix($version, $levels, $cells, $this->admin());

        $this->assertSame('high', app(RiskMatrix::class)->category($changed, $cells[0]['frequency_key'], $cells[0]['impact_key'])->key);
        $event = AuditEvent::query()->where('event_type', 'risk_methodology.draft_matrix_replaced')->sole();
        $this->assertEqualsCanonicalizing(['impact_levels', 'matrix_cells'], $event->context['changed_fields']);
        $this->assertStringNotContainsString('Secret impact description', json_encode($event->context, JSON_THROW_ON_ERROR));
    }

    public function test_reordering_equivalent_cells_does_not_claim_a_matrix_change(): void
    {
        $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $cells = array_reverse($this->cells($version));

        app(RiskMethodologyPublisher::class)->replaceDraftMatrix($version, $this->levels($version), $cells, $this->admin());

        $event = AuditEvent::query()->where('event_type', 'risk_methodology.draft_matrix_replaced')->sole();
        $this->assertSame([], $event->context['changed_fields']);
    }

    public function test_publication_rolls_back_if_audit_fails(): void
    {
        $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                throw new RuntimeException('Audit unavailable');
            }
        });

        try {
            app(RiskMethodologyPublisher::class)->publish($version, $this->admin());
            $this->fail('Audit failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
            $this->assertSame(PublicationStatus::Draft, $version->fresh()->status);
            $this->assertNull($version->fresh()->published_at);
        }
    }

    private function admin(): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function levels(RiskMethodologyVersion $version): array
    {
        return [
            'frequency' => $version->frequencies->map->only(['key', 'label', 'description', 'sort_order'])->all(),
            'impact' => $version->impacts->map->only(['key', 'label', 'description', 'sort_order'])->all(),
            'risk' => $version->riskLevels->map->only(['key', 'label', 'description', 'sort_order'])->all(),
        ];
    }

    private function cells(RiskMethodologyVersion $version): array
    {
        return $version->matrixCells()->with(['frequency', 'impact', 'riskLevel'])->get()
            ->map(fn ($cell) => [
                'frequency_key' => $cell->frequency->key,
                'impact_key' => $cell->impact->key,
                'risk_key' => $cell->riskLevel->key,
            ])->all();
    }
}
