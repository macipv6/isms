<?php

namespace App\Services\Assessment;

use App\Enums\RuleAction;
use App\Enums\RuleOperator;
use App\Models\AssessmentQuestion;
use App\Models\ProjectAnswer;
use App\Models\ProjectAssessment;
use DomainException;
use Illuminate\Support\Collection;

class ApplicabilityEvaluator
{
    /**
     * @param  array<string, mixed>  $answers
     * @return Collection<int, AssessmentQuestion>
     */
    public function applicableQuestions(ProjectAssessment $assessment, ?array $answers = null): Collection
    {
        $answers ??= $this->answerValues($assessment);

        return $assessment->questions()
            ->where('is_active', true)
            ->with('answer')
            ->get()
            ->filter(fn (AssessmentQuestion $question): bool => $this->isApplicable($question, $answers))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function answerValues(ProjectAssessment $assessment): array
    {
        $values = [];

        $assessment->answers()
            ->with('question')
            ->get()
            ->each(function (ProjectAnswer $answer) use (&$values): void {
                $values[$answer->question->question_key] = $answer->valueForRules();
            });

        return $values;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function isApplicable(AssessmentQuestion $question, array $answers): bool
    {
        $includeMatches = [];

        foreach ($question->rules as $rule) {
            $action = RuleAction::tryFrom($rule['action']);

            if (! $action instanceof RuleAction) {
                throw new DomainException('Unknown assessment rule action.');
            }

            $matches = $this->matches($rule, $answers);

            if ($action === RuleAction::Exclude && $matches) {
                return false;
            }

            if ($action === RuleAction::Include) {
                $includeMatches[] = $matches;
            }
        }

        return $includeMatches === [] || ! in_array(false, $includeMatches, true);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $answers
     */
    private function matches(array $rule, array $answers): bool
    {
        $operator = RuleOperator::tryFrom($rule['operator']);

        if (! $operator instanceof RuleOperator) {
            throw new DomainException('Unknown assessment rule operator.');
        }

        $actual = $answers[$rule['trigger_question_key']] ?? null;
        $expected = $rule['expected_value'];

        return match ($operator) {
            RuleOperator::Equals => $actual === $expected,
            RuleOperator::NotEquals => $actual !== $expected,
            RuleOperator::Contains => is_array($actual)
                && in_array($expected, $actual, true),
        };
    }
}
