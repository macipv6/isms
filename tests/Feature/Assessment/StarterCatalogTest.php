<?php

namespace Tests\Feature\Assessment;

use App\Enums\AnswerType;
use App\Enums\CatalogStatus;
use App\Enums\RuleAction;
use App\Enums\RuleOperator;
use App\Models\CatalogQuestion;
use App\Models\CatalogVersion;
use App\Models\Framework;
use App\Models\QuestionCategory;
use App\Models\QuestionOption;
use App\Models\QuestionRule;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StarterCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_catalog_is_published_with_representative_topics_and_answer_types(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);

        $framework = Framework::query()->sole();
        $catalog = CatalogVersion::publishedForFramework('BSI');
        $questionKeys = $catalog->questions()->pluck('question_key');
        $answerTypes = $catalog->questions()->get()->pluck('answer_type')->all();

        $this->assertSame('BSI', $framework->key);
        $this->assertTrue($framework->is_active);
        $this->assertSame('2026.1', $catalog->version);
        $this->assertSame(CatalogStatus::Published, $catalog->status);
        $this->assertGreaterThanOrEqual(8, $catalog->categories()->count());
        $this->assertGreaterThanOrEqual(18, $questionKeys->count());
        $this->assertLessThanOrEqual(24, $questionKeys->count());
        $this->assertSame($questionKeys->count(), $questionKeys->unique()->count());
        $this->assertEqualsCanonicalizing([
            AnswerType::Boolean,
            AnswerType::SingleChoice,
            AnswerType::MultipleChoice,
            AnswerType::Text,
            AnswerType::Number,
        ], array_values(array_unique($answerTypes, SORT_REGULAR)));
        $this->assertTrue($questionKeys->contains('cloud.m365_used'));
        $this->assertTrue($questionKeys->contains('backup.available'));
        $this->assertTrue($questionKeys->contains('backup.restore_test'));
    }

    public function test_starter_catalog_contains_m365_and_backup_follow_up_rules(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);

        $m365Rule = QuestionRule::query()
            ->whereHas('triggerQuestion', fn ($query) => $query->where('question_key', 'cloud.m365_used'))
            ->whereHas('targetQuestion', fn ($query) => $query->where('question_key', 'cloud.m365_mfa'))
            ->sole();
        $backupRules = QuestionRule::query()
            ->whereHas('triggerQuestion', fn ($query) => $query->where('question_key', 'backup.available'))
            ->get();

        $this->assertSame(RuleOperator::Equals, $m365Rule->operator);
        $this->assertSame(true, $m365Rule->expected_value);
        $this->assertSame(RuleAction::Include, $m365Rule->action);
        $this->assertGreaterThanOrEqual(4, $backupRules->count());
        $this->assertTrue($backupRules->every(
            fn (QuestionRule $rule): bool => $rule->operator === RuleOperator::Equals
                && $rule->expected_value === true
                && $rule->action === RuleAction::Include,
        ));
    }

    public function test_starter_catalog_seeding_is_idempotent(): void
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $firstCounts = $this->catalogCounts();

        $this->seed(AssessmentCatalogSeeder::class);

        $this->assertSame($firstCounts, $this->catalogCounts());
    }

    /**
     * @return array<string, int>
     */
    private function catalogCounts(): array
    {
        return [
            'frameworks' => Framework::query()->count(),
            'versions' => CatalogVersion::query()->count(),
            'categories' => QuestionCategory::query()->count(),
            'questions' => CatalogQuestion::query()->count(),
            'options' => QuestionOption::query()->count(),
            'rules' => QuestionRule::query()->count(),
        ];
    }
}
