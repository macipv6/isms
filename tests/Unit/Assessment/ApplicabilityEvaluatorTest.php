<?php

namespace Tests\Unit\Assessment;

use App\Enums\AnswerType;
use App\Models\AssessmentQuestion;
use App\Services\Assessment\ApplicabilityEvaluator;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicabilityEvaluatorTest extends TestCase
{
    private ApplicabilityEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = app(ApplicabilityEvaluator::class);
    }

    #[Test]
    public function question_without_rules_is_applicable(): void
    {
        $this->assertTrue($this->evaluator->isApplicable($this->question(), []));
    }

    #[Test]
    public function unanswered_or_false_boolean_trigger_keeps_include_target_hidden(): void
    {
        $question = $this->question([
            $this->rule('backup.available', 'equals', true, 'include'),
        ]);

        $this->assertFalse($this->evaluator->isApplicable($question, []));
        $this->assertFalse($this->evaluator->isApplicable($question, ['backup.available' => false]));
    }

    #[Test]
    public function matching_boolean_trigger_reveals_include_target(): void
    {
        $question = $this->question([
            $this->rule('backup.available', 'equals', true, 'include'),
        ]);

        $this->assertTrue($this->evaluator->isApplicable($question, ['backup.available' => true]));
    }

    #[Test]
    public function contains_matches_one_value_in_multiple_choice_answer(): void
    {
        $question = $this->question([
            $this->rule('suppliers.requirements', 'contains', 'audit_rights', 'include'),
        ]);

        $this->assertTrue($this->evaluator->isApplicable($question, [
            'suppliers.requirements' => ['availability', 'audit_rights'],
        ]));
        $this->assertFalse($this->evaluator->isApplicable($question, [
            'suppliers.requirements' => ['availability'],
        ]));
    }

    #[Test]
    public function unanswered_not_equals_trigger_does_not_apply_include_or_exclude_rule(): void
    {
        $includeQuestion = $this->question([
            $this->rule('cloud.provider', 'not_equals', 'none', 'include'),
        ]);
        $excludeQuestion = $this->question([
            $this->rule('cloud.provider', 'not_equals', 'none', 'exclude'),
        ]);

        $this->assertFalse($this->evaluator->isApplicable($includeQuestion, []));
        $this->assertTrue($this->evaluator->isApplicable($excludeQuestion, []));
    }

    #[Test]
    public function all_include_rules_must_match_and_matching_exclude_rule_wins(): void
    {
        $question = $this->question([
            $this->rule('cloud.used', 'equals', true, 'include'),
            $this->rule('cloud.provider', 'not_equals', 'none', 'include'),
            $this->rule('project.archived', 'equals', true, 'exclude'),
        ]);

        $this->assertFalse($this->evaluator->isApplicable($question, [
            'cloud.used' => true,
            'cloud.provider' => 'none',
            'project.archived' => false,
        ]));
        $this->assertTrue($this->evaluator->isApplicable($question, [
            'cloud.used' => true,
            'cloud.provider' => 'm365',
            'project.archived' => false,
        ]));
        $this->assertFalse($this->evaluator->isApplicable($question, [
            'cloud.used' => true,
            'cloud.provider' => 'm365',
            'project.archived' => true,
        ]));
    }

    #[Test]
    public function unknown_rule_configuration_is_rejected(): void
    {
        $question = $this->question([
            $this->rule('backup.available', 'executes', true, 'include'),
        ]);

        $this->expectException(DomainException::class);
        $this->evaluator->isApplicable($question, ['backup.available' => true]);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function question(array $rules = []): AssessmentQuestion
    {
        return new AssessmentQuestion([
            'question_key' => 'target.question',
            'answer_type' => AnswerType::Boolean,
            'rules' => $rules,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(string $trigger, string $operator, mixed $expected, string $action): array
    {
        return [
            'trigger_question_key' => $trigger,
            'operator' => $operator,
            'expected_value' => $expected,
            'action' => $action,
        ];
    }
}
