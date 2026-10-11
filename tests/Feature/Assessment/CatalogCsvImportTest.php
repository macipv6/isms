<?php

namespace Tests\Feature\Assessment;

use App\Enums\UserRole;
use App\Models\CatalogImportBatch;
use App\Models\CatalogQuestion;
use App\Models\CatalogVersion;
use App\Models\Framework;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Assessment\AssessmentStarter;
use App\Services\Assessment\CatalogCsvImporter;
use App\Services\Assessment\CatalogCsvParser;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(array $overrides = [], string $delimiter = ';'): string
    {
        $row = array_combine(CatalogCsvParser::HEADER, ['example', 'Beispiel', '', '10', 'example.first', 'Beispiel', "Eigene Frage,\nmit zweiter Zeile?", '', 'boolean', 'medium', '0', '1', '10', '[]', '[]']);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, CatalogCsvParser::HEADER, $delimiter, '"', '');
        fputcsv($stream, array_values(array_replace($row, $overrides)), $delimiter, '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function setupImport(): array
    {
        $this->seed(AssessmentCatalogSeeder::class);

        return [User::factory()->create(['role' => UserRole::Admin]), Framework::query()->sole(), app(CatalogCsvImporter::class)];
    }

    public function test_preview_confirm_and_snapshot_retention(): void
    {
        [$admin, $framework] = $this->setupImport();
        $project = IsmsProject::factory()->create(['framework' => 'BSI']);
        $assessment = app(AssessmentStarter::class)->start($project, $admin);
        $oldQuestions = $assessment->questions()->get()->toArray();
        $this->actingAs($admin)->post('/assessment-catalogs/preview', [
            'framework_id' => $framework->id, 'version' => '2026.2', 'csv' => UploadedFile::fake()->createWithContent('questions.csv', $this->csv()),
        ])->assertRedirect();
        $batch = CatalogImportBatch::query()->sole();
        $this->assertStringNotContainsString('Eigene Frage', $batch->getRawOriginal('payload'));
        $this->actingAs($admin)->get('/assessment-catalogs/previews/'.$batch->id)->assertOk();
        $this->actingAs($admin)->post('/assessment-catalogs/previews/'.$batch->id.'/confirm')->assertRedirect('/assessment-catalogs');
        $catalog = CatalogVersion::query()->where('version', '2026.2')->sole();
        $this->assertSame('published', $catalog->status->value);
        $this->assertSame($oldQuestions, $assessment->questions()->get()->toArray());
        $this->assertSame([], $batch->fresh()->payload);
        $this->actingAs($admin)->post('/assessment-catalogs/previews/'.$batch->id.'/confirm')->assertSessionHasErrors('csv');
    }

    public function test_authorization_ownership_expiry_and_conflict(): void
    {
        [$admin, $framework, $importer] = $this->setupImport();
        $batch = $importer->preview($admin, $framework->id, '2026.2', $this->csv());
        $other = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($other)->get('/assessment-catalogs/previews/'.$batch->id)->assertNotFound();
        $this->actingAs($other)->post('/assessment-catalogs/previews/'.$batch->id.'/confirm')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/assessment-catalogs')->assertForbidden();
        $this->actingAs($admin)->get('/assessment-catalogs/previews/00000000-0000-0000-0000-000000000000')->assertNotFound();
        $batch->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($admin)->post('/assessment-catalogs/previews/'.$batch->id.'/confirm')->assertSessionHasErrors('csv');
        $batch->update(['expires_at' => now()->addMinute()]);
        CatalogVersion::query()->create(['framework_id' => $framework->id, 'version' => '2026.2', 'status' => 'draft']);
        $this->actingAs($admin)->post('/assessment-catalogs/previews/'.$batch->id.'/confirm')->assertSessionHasErrors('version');
        $this->assertNull($batch->fresh()->applied_at);
        $admin->update(['role' => UserRole::Consultant]);
        $this->actingAs($admin)->post('/assessment-catalogs/previews/'.$batch->id.'/confirm')->assertForbidden();
        $admin->update(['role' => UserRole::Admin]);
        $admin->organization->update(['is_active' => false]);
        $this->actingAs($admin)->get('/assessment-catalogs')->assertForbidden();
        $admin->organization->update(['is_active' => true]);
        $admin->organization->update(['organization_type' => 'customer']);
        $this->actingAs($admin)->post('/assessment-catalogs/previews/'.$batch->id.'/confirm')->assertForbidden();
    }

    public function test_invalid_rows_and_rules_never_create_a_batch_or_catalog(): void
    {
        [$admin, $framework, $importer] = $this->setupImport();
        foreach ([['answer_type' => 'invalid'], ['rules_json' => '[{"target_question_key":"missing","operator":"equals","action":"include","expected_value":true}]'], ['options_json' => '{bad}'], ['title' => '=FORMULA()']] as $overrides) {
            try {
                $importer->preview($admin, $framework->id, '2026.2', $this->csv($overrides));
                $this->fail('Invalid CSV accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('catalog_import_batches', 0);
                $this->assertDatabaseCount('catalog_versions', 1);
            }
        }
        $this->assertCount(1, app(CatalogCsvParser::class)->parse($this->csv([], ',')));
    }

    public function test_database_failure_rolls_back_catalog_and_leaves_batch_unused(): void
    {
        [$admin, $framework, $importer] = $this->setupImport();
        $batch = $importer->preview($admin, $framework->id, '2026.2', $this->csv());
        Event::listen('eloquent.creating: '.CatalogQuestion::class, function (): void { throw new \RuntimeException('Simulated write failure'); });
        try {
            $importer->confirm($admin, $batch->id);
            $this->fail('Expected write failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated write failure', $exception->getMessage());
            $this->assertDatabaseCount('catalog_versions', 1);
            $this->assertNull($batch->fresh()->applied_at);
        }
    }
}
