<?php

namespace Tests\Feature\Risks;

use App\Models\RiskMethodologyVersion;
use App\Services\RiskMethodology\RiskMatrix;
use Database\Seeders\RiskMethodologySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RiskMethodologySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_methodology_contains_the_exact_bsi_matrix(): void
    {
        $this->seed(RiskMethodologySeeder::class);
        $version = RiskMethodologyVersion::query()->where('version', 1)->sole();

        $this->assertSame('bsi-200-3', $version->methodology->key);
        $this->assertSame('published', $version->status->value);
        $this->assertCount(4, $version->frequencies);
        $this->assertCount(4, $version->impacts);
        $this->assertCount(4, $version->riskLevels);
        $this->assertCount(16, $version->matrixCells);

        $expected = [
            'negligible' => ['rare' => 'low', 'medium' => 'low', 'frequent' => 'low', 'very_frequent' => 'medium'],
            'limited' => ['rare' => 'low', 'medium' => 'low', 'frequent' => 'high', 'very_frequent' => 'high'],
            'considerable' => ['rare' => 'medium', 'medium' => 'high', 'frequent' => 'very_high', 'very_frequent' => 'very_high'],
            'existential' => ['rare' => 'high', 'medium' => 'very_high', 'frequent' => 'very_high', 'very_frequent' => 'very_high'],
        ];

        foreach ($expected as $impact => $row) {
            foreach ($row as $frequency => $risk) {
                $this->assertSame($risk, app(RiskMatrix::class)->category($version, $frequency, $impact)->key);
            }
        }
    }

    public function test_default_axis_descriptions_match_bsi_standard_200_3(): void
    {
        $this->seed(RiskMethodologySeeder::class);
        $version = RiskMethodologyVersion::query()->sole();

        $this->assertSame([
            'rare' => 'Ereignis könnte nach heutigem Kenntnisstand höchstens alle fünf Jahre eintreten.',
            'medium' => 'Ereignis tritt einmal alle fünf Jahre bis einmal im Jahr ein.',
            'frequent' => 'Ereignis tritt einmal im Jahr bis einmal pro Monat ein.',
            'very_frequent' => 'Ereignis tritt mehrmals im Monat ein.',
        ], $version->frequencies->pluck('description', 'key')->all());
        $this->assertSame([
            'negligible' => 'Die Schadensauswirkungen sind gering und können vernachlässigt werden.',
            'limited' => 'Die Schadensauswirkungen sind begrenzt und überschaubar.',
            'considerable' => 'Die Schadensauswirkungen können beträchtlich sein.',
            'existential' => 'Die Schadensauswirkungen können ein existenziell bedrohliches, katastrophales Ausmaß erreichen.',
        ], $version->impacts->pluck('description', 'key')->all());
        $this->assertSame([
            'low' => 'Die bereits umgesetzten oder zumindest im Sicherheitskonzept vorgesehenen Sicherheitsmaßnahmen bieten einen ausreichenden Schutz. In der Praxis ist es üblich, geringe Risiken zu akzeptieren und die Gefährdung dennoch zu beobachten.',
            'medium' => 'Die bereits umgesetzten oder zumindest im Sicherheitskonzept vorgesehenen Sicherheitsmaßnahmen reichen möglicherweise nicht aus.',
            'high' => 'Die bereits umgesetzten oder zumindest im Sicherheitskonzept vorgesehenen Sicherheitsmaßnahmen bieten keinen ausreichenden Schutz vor der jeweiligen Gefährdung.',
            'very_high' => 'Die bereits umgesetzten oder zumindest im Sicherheitskonzept vorgesehenen Sicherheitsmaßnahmen bieten keinen ausreichenden Schutz vor der jeweiligen Gefährdung. In der Praxis werden sehr hohe Risiken selten akzeptiert.',
        ], $version->riskLevels->pluck('description', 'key')->all());
    }

    public function test_version_number_is_unique_within_methodology(): void
    {
        $this->seed(RiskMethodologySeeder::class);
        $version = RiskMethodologyVersion::query()->sole();
        $this->assertTrue(Str::isUuid($version->id));

        $this->assertDatabaseCount('risk_methodologies', 1);
        $this->assertDatabaseCount('risk_methodology_versions', 1);

        $this->expectException(QueryException::class);
        RiskMethodologyVersion::query()->create([
            'methodology_id' => $version->methodology_id,
            'version' => 1,
            'status' => 'draft',
            'description' => 'Duplicate',
        ]);
    }

    public function test_matrix_rejects_keys_missing_from_the_supplied_version(): void
    {
        $this->seed(RiskMethodologySeeder::class);
        $version = RiskMethodologyVersion::query()->sole();

        $this->expectException(ValidationException::class);
        app(RiskMatrix::class)->category($version, 'unknown', 'negligible');
    }

    public function test_level_keys_orders_and_matrix_coordinates_are_unique(): void
    {
        $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $frequency = $version->frequencies()->firstOrFail();
        $impact = $version->impacts()->firstOrFail();
        $risk = $version->riskLevels()->firstOrFail();

        $this->assertRejected(fn () => DB::table('risk_frequency_levels')->insert([
            'id' => Str::uuid(), 'version_id' => $version->id, 'key' => $frequency->key,
            'label' => 'Duplicate key', 'sort_order' => 10,
        ]));
        $this->assertRejected(fn () => DB::table('risk_impact_levels')->insert([
            'id' => Str::uuid(), 'version_id' => $version->id, 'key' => 'another',
            'label' => 'Duplicate order', 'sort_order' => $impact->sort_order,
        ]));
        $this->assertRejected(fn () => DB::table('risk_levels')->insert([
            'id' => Str::uuid(), 'version_id' => $version->id, 'key' => 'zero',
            'label' => 'Invalid order', 'sort_order' => 0,
        ]));
        $this->assertRejected(fn () => DB::table('risk_matrix_cells')->insert([
            'id' => Str::uuid(), 'version_id' => $version->id,
            'frequency_level_id' => $frequency->id, 'impact_level_id' => $impact->id,
            'risk_level_id' => $risk->id,
        ]));
    }

    public function test_matrix_foreign_keys_reject_levels_from_other_versions(): void
    {
        $first = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $second = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
        $cell = $first->matrixCells()->firstOrFail();
        $cell->delete();

        foreach (['frequency_level_id' => 'frequencies', 'impact_level_id' => 'impacts', 'risk_level_id' => 'riskLevels'] as $column => $relation) {
            $this->assertRejected(fn () => DB::table('risk_matrix_cells')->insert([
                'id' => Str::uuid(), 'version_id' => $first->id,
                'frequency_level_id' => $column === 'frequency_level_id' ? $second->{$relation}()->firstOrFail()->id : $cell->frequency_level_id,
                'impact_level_id' => $column === 'impact_level_id' ? $second->{$relation}()->firstOrFail()->id : $cell->impact_level_id,
                'risk_level_id' => $column === 'risk_level_id' ? $second->{$relation}()->firstOrFail()->id : $cell->risk_level_id,
            ]));
        }
    }

    public function test_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_09_22_060000_create_risk_methodology_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('risk_methodologies'));
        $this->assertFalse(Schema::hasTable('risk_matrix_cells'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('risk_methodology_versions'));
        $this->assertTrue(Schema::hasTable('risk_matrix_cells'));
    }

    private function assertRejected(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('The database should reject this record.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
