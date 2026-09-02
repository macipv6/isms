<?php

namespace Tests\Feature\Assessment;

use App\Enums\CatalogStatus;
use App\Models\CatalogVersion;
use App\Models\Framework;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_tables_contain_the_versioned_question_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('frameworks', [
            'id',
            'key',
            'name',
            'description',
            'is_active',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_versions', [
            'id',
            'framework_id',
            'version',
            'status',
            'published_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('question_categories', [
            'id',
            'catalog_version_id',
            'key',
            'name',
            'description',
            'sort_order',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_questions', [
            'id',
            'catalog_version_id',
            'question_category_id',
            'question_key',
            'title',
            'question_text',
            'help_text',
            'answer_type',
            'severity',
            'evidence_expected',
            'is_active',
            'sort_order',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('question_options', [
            'id',
            'catalog_question_id',
            'value',
            'label',
            'score',
            'sort_order',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('question_rules', [
            'id',
            'catalog_version_id',
            'trigger_question_id',
            'target_question_id',
            'operator',
            'expected_value',
            'action',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_framework_versions_are_unique_and_latest_published_version_is_selected(): void
    {
        $framework = Framework::query()->create([
            'key' => 'BSI',
            'name' => 'BSI-orientiertes ISMS',
            'is_active' => true,
        ]);
        CatalogVersion::query()->create([
            'framework_id' => $framework->id,
            'version' => '2025.1',
            'status' => CatalogStatus::Published,
            'published_at' => '2025-01-01 00:00:00+00',
        ]);
        CatalogVersion::query()->create([
            'framework_id' => $framework->id,
            'version' => '2026.1',
            'status' => CatalogStatus::Published,
            'published_at' => '2026-09-01 00:00:00+00',
        ]);
        CatalogVersion::query()->create([
            'framework_id' => $framework->id,
            'version' => '2027.1',
            'status' => CatalogStatus::Draft,
        ]);

        $this->assertSame(
            '2026.1',
            CatalogVersion::publishedForFramework('BSI')->version,
        );

        $this->expectException(QueryException::class);
        CatalogVersion::query()->create([
            'framework_id' => $framework->id,
            'version' => '2026.1',
            'status' => CatalogStatus::Draft,
        ]);
    }

    public function test_inactive_framework_cannot_supply_a_catalog_for_new_assessments(): void
    {
        $framework = Framework::query()->create([
            'key' => 'BSI',
            'name' => 'BSI-orientiertes ISMS',
            'is_active' => false,
        ]);
        CatalogVersion::query()->create([
            'framework_id' => $framework->id,
            'version' => '2026.1',
            'status' => CatalogStatus::Published,
            'published_at' => '2026-09-01 00:00:00+00',
        ]);

        $this->expectException(ModelNotFoundException::class);
        CatalogVersion::publishedForFramework('BSI');
    }
}
