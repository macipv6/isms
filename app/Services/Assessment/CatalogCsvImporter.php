<?php

namespace App\Services\Assessment;

use App\Enums\CatalogStatus;
use App\Models\CatalogImportBatch;
use App\Models\CatalogQuestion;
use App\Models\CatalogVersion;
use App\Models\Framework;
use App\Models\QuestionCategory;
use App\Models\QuestionRule;
use App\Models\User;
use App\Policies\ProjectRiskPolicy;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogCsvImporter
{
    public function __construct(private readonly CatalogCsvParser $parser, private readonly AuditLogger $audit) {}

    public function authorize(User $actor): void
    {
        abort_unless(app(ProjectRiskPolicy::class)->publishGlobal($actor), 403);
    }

    public function preview(User $actor, string $frameworkId, string $version, string $contents): CatalogImportBatch
    {
        $this->authorize($actor);
        if (! preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $version)) {
            throw ValidationException::withMessages(['version' => 'Ungültige Versionsbezeichnung: maximal 32 Buchstaben, Ziffern, Punkte, Unterstriche oder Bindestriche.']);
        }
        Framework::query()->where('is_active', true)->findOrFail($frameworkId);
        $this->checkVersion($frameworkId, $version);
        $rows = $this->parser->parse($contents);
        $batch = CatalogImportBatch::query()->create([
            'created_by' => $actor->id, 'framework_id' => $frameworkId, 'version' => $version,
            'payload' => $rows, 'expires_at' => now()->addMinutes(30),
        ]);
        $this->audit->record('assessment_catalog.previewed', $actor, ['import_batch_id' => $batch->id, 'source_id' => $frameworkId, 'row_counts' => ['questions' => count($rows)]]);

        return $batch;
    }

    public function batch(User $actor, string $id): CatalogImportBatch
    {
        $this->authorize($actor);
        $batch = CatalogImportBatch::query()->where('created_by', $actor->id)->findOrFail($id);
        $this->usable($batch);

        return $batch;
    }

    public function confirm(User $actor, string $id): CatalogVersion
    {
        return DB::transaction(function () use ($actor, $id): CatalogVersion {
            // Re-read current authorization; previews never grant publishing rights.
            $this->authorize($actor);
            $batch = CatalogImportBatch::query()->where('created_by', $actor->id)->lockForUpdate()->findOrFail($id);
            $this->usable($batch);
            $framework = Framework::query()->lockForUpdate()->findOrFail($batch->framework_id);
            abort_unless($framework->is_active, 403);
            $this->checkVersion($framework->id, $batch->version);
            $catalog = CatalogVersion::query()->create(['framework_id' => $framework->id, 'version' => $batch->version, 'status' => CatalogStatus::Draft]);
            $categories = [];
            $questions = [];
            $rows = $batch->payload;
            $optionCount = $ruleCount = 0;
            foreach ($rows as $row) {
                $categoryKey = $row['category_key'];
                $categories[$categoryKey] ??= QuestionCategory::query()->create([
                    'catalog_version_id' => $catalog->id, 'key' => $categoryKey, 'name' => $row['category_name'],
                    'description' => $row['category_description'] ?: null, 'sort_order' => $row['category_sort_order'],
                ]);
                $question = CatalogQuestion::query()->create([
                    'catalog_version_id' => $catalog->id, 'question_category_id' => $categories[$categoryKey]->id,
                    ...array_intersect_key($row, array_flip(['question_key', 'title', 'question_text', 'help_text', 'answer_type', 'severity', 'evidence_expected', 'is_active', 'sort_order'])),
                ]);
                $questions[$row['question_key']] = $question;
                foreach ($row['options_json'] as $option) {
                    $question->options()->create($option);
                    $optionCount++;
                }
            }
            foreach ($rows as $row) {
                foreach ($row['rules_json'] as $rule) {
                    QuestionRule::query()->create([
                        'catalog_version_id' => $catalog->id, 'trigger_question_id' => $questions[$row['question_key']]->id,
                        'target_question_id' => $questions[$rule['target_question_key']]->id,
                        'operator' => $rule['operator'], 'action' => $rule['action'], 'expected_value' => $rule['expected_value'],
                    ]);
                    $ruleCount++;
                }
            }
            $catalog->update(['status' => CatalogStatus::Published, 'published_at' => now()]);
            $batch->update(['applied_at' => now(), 'payload' => []]);
            $this->audit->record('assessment_catalog.imported', $actor, [
                'import_batch_id' => $batch->id, 'source_id' => $framework->id, 'target_id' => $catalog->id,
                'row_counts' => ['categories' => count($categories), 'questions' => count($rows), 'options' => $optionCount, 'rules' => $ruleCount],
            ]);

            return $catalog;
        });
    }

    private function usable(CatalogImportBatch $batch): void
    {
        if ($batch->applied_at || $batch->expires_at->isPast()) {
            throw ValidationException::withMessages(['csv' => 'Diese Vorschau ist abgelaufen oder wurde bereits importiert. Bitte erneut hochladen.']);
        }
    }

    private function checkVersion(string $frameworkId, string $version): void
    {
        if (CatalogVersion::query()->where('framework_id', $frameworkId)->where('version', $version)->exists()) {
            throw ValidationException::withMessages(['version' => 'Diese Versionsbezeichnung existiert bereits. Bitte eine neue Version wählen.']);
        }
    }
}
