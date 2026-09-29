<?php

namespace Tests\Feature\Risks;

use App\Enums\PublicationStatus;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Threats\ThreatCatalogPublisher;
use Database\Seeders\ThreatCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ThreatCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_contains_the_exact_47_ordered_2023_threats(): void
    {
        $this->seed(ThreatCatalogSeeder::class);
        $version = ThreatCatalogVersion::query()->sole();
        $entries = $version->entries()->orderBy('sort_order')->get();

        $this->assertSame(PublicationStatus::Published, $version->status);
        $this->assertSame('2023', $version->source_edition);
        $this->assertCount(47, $entries);
        $this->assertSame(array_map(fn ($n) => 'G 0.'.$n, range(1, 47)), $entries->pluck('code')->all());
        $this->assertSame('Feuer', $entries[0]->title);
        $this->assertSame('Schädliche Seiteneffekte IT-gestützter Angriffe', $entries[46]->title);
        $this->assertTrue($entries->every(fn ($entry) => $entry->confidentiality || $entry->integrity || $entry->availability));
        $this->seed(ThreatCatalogSeeder::class);
        $this->assertSame(47, $version->entries()->count());
    }

    public function test_published_versions_and_entries_reject_direct_database_mutation(): void
    {
        $this->seed(ThreatCatalogSeeder::class);
        $version = ThreatCatalogVersion::query()->sole();
        $entry = $version->entries()->firstOrFail();

        foreach ([
            fn () => DB::table('threat_catalog_entries')->where('id', $entry->id)->update(['title' => 'Changed']),
            fn () => DB::table('threat_catalog_entries')->where('id', $entry->id)->delete(),
            fn () => DB::table('threat_catalog_entries')->insert(['id' => Str::uuid(), 'version_id' => $version->id, 'code' => 'G 0.48', 'title' => 'Extra', 'description' => 'Extra', 'sort_order' => 48, 'availability' => true, 'source_edition' => '2023']),
            fn () => DB::table('threat_catalog_versions')->where('id', $version->id)->update(['description' => 'Changed']),
            fn () => DB::table('threat_catalog_versions')->where('id', $version->id)->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Published data must be immutable.');
            } catch (QueryException) {
                $this->assertSame('Feuer', $entry->fresh()->title);
            }
        }
    }

    public function test_admin_can_clone_edit_and_publish_but_consultant_cannot(): void
    {
        $this->seed(ThreatCatalogSeeder::class);
        $source = ThreatCatalogVersion::query()->sole();
        $admin = $this->actor(UserRole::Admin);
        $publisher = app(ThreatCatalogPublisher::class);
        $clone = $publisher->clonePublished($source, $admin);
        $this->assertSame(2, $clone->version);
        $this->assertSame(PublicationStatus::Draft, $clone->status);
        $this->assertCount(47, $clone->entries);
        $this->assertNotSame($source->entries()->firstOrFail()->id, $clone->entries()->firstOrFail()->id);

        $publisher->upsertDraftEntry($clone, ['code' => 'G 0.48', 'title' => 'Zusatz', 'description' => 'Konkrete zusätzliche Gefährdung', 'sort_order' => 48, 'confidentiality' => true, 'integrity' => false, 'availability' => false, 'source_edition' => '2023'], $admin);
        $published = $publisher->publish($clone, $admin);
        $this->assertSame(PublicationStatus::Published, $published->status);
        $this->assertCount(48, $published->entries);
        $this->assertCount(47, $source->fresh()->entries);
        $this->assertSame(['source_id' => $clone->catalog_id, 'target_id' => $clone->id], AuditEvent::query()->where('event_type', 'threat_catalog.published')->where('actor_user_id', $admin->id)->sole()->context);

        $consultant = $this->actor(UserRole::Consultant);
        $this->expectException(ValidationException::class);
        $publisher->clonePublished($source, $consultant);
    }

    public function test_publish_rejects_empty_invalid_and_stale_actor_and_rolls_back_audit_failure(): void
    {
        $draft = ThreatCatalogVersion::factory()->create();
        $admin = $this->actor(UserRole::Admin);
        $publisher = app(ThreatCatalogPublisher::class);
        try {
            $publisher->publish($draft, $admin);
            $this->fail('Empty catalog published.');
        } catch (ValidationException) {
            $this->assertSame(PublicationStatus::Draft, $draft->fresh()->status);
        }
        try {
            $publisher->upsertDraftEntry($draft, ['code' => 'G 0.1', 'title' => 'Bad', 'description' => 'Bad', 'sort_order' => 1, 'confidentiality' => false, 'integrity' => false, 'availability' => false], $admin);
            $this->fail('CIA-less entry accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, $draft->entries()->count());
        }
        $publisher->upsertDraftEntry($draft, ['code' => 'G 0.1', 'title' => 'Test', 'description' => 'Concrete test threat', 'sort_order' => 1, 'availability' => true], $admin);
        DB::table('users')->where('id', $admin->id)->update(['is_active' => false]);
        try {
            $publisher->publish($draft, $admin);
            $this->fail('Stale admin accepted.');
        } catch (ValidationException) {
            $this->assertSame(PublicationStatus::Draft, $draft->fresh()->status);
        }

        DB::table('users')->where('id', $admin->id)->update(['is_active' => true]);
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(ThreatCatalogPublisher::class)->publish($draft, $admin);
            $this->fail('Audit failure should roll back.');
        } catch (RuntimeException) {
            $this->assertSame(PublicationStatus::Draft, $draft->fresh()->status);
        }
    }

    public function test_adapted_catalog_accepts_a_stable_local_code(): void
    {
        $draft = ThreatCatalogVersion::factory()->create();
        $entry = app(ThreatCatalogPublisher::class)->upsertDraftEntry($draft, [
            'code' => 'ORG-1',
            'title' => 'Lokale Gefährdung',
            'description' => 'Eine organisationsspezifische Gefährdung.',
            'sort_order' => 1,
            'availability' => true,
        ], $this->actor(UserRole::Admin));

        $this->assertSame('ORG-1', $entry->code);
    }

    private function actor(UserRole $role): User
    {
        return User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => $role, 'is_active' => true]);
    }
}
