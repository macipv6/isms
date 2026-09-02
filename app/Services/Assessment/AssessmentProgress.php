<?php

namespace App\Services\Assessment;

use App\Models\AssessmentQuestion;
use App\Models\ProjectAssessment;
use Illuminate\Support\Collection;

class AssessmentProgress
{
    public function __construct(
        private readonly ApplicabilityEvaluator $evaluator,
    ) {}

    /**
     * @return array{
     *     answered: int,
     *     total: int,
     *     percentage: int,
     *     categories: list<array{key: string, name: string, answered: int, total: int, percentage: int}>
     * }
     */
    public function for(ProjectAssessment $assessment): array
    {
        $questions = $this->evaluator->applicableQuestions($assessment);
        $answeredIds = $assessment->answers()
            ->pluck('assessment_question_id')
            ->all();
        $categories = [];

        foreach ($questions->groupBy('category_key') as $key => $categoryQuestions) {
            /** @var Collection<int, AssessmentQuestion> $categoryQuestions */
            /** @var AssessmentQuestion $first */
            $first = $categoryQuestions->first();
            $total = $categoryQuestions->count();
            $answered = $categoryQuestions
                ->whereIn('id', $answeredIds)
                ->count();
            $categories[] = [
                'key' => $key,
                'name' => $first->category_name,
                'answered' => $answered,
                'total' => $total,
                'percentage' => $this->percentage($answered, $total),
            ];
        }

        $total = $questions->count();
        $answered = $questions->whereIn('id', $answeredIds)->count();

        return [
            'answered' => $answered,
            'total' => $total,
            'percentage' => $this->percentage($answered, $total),
            'categories' => $categories,
        ];
    }

    private function percentage(int $answered, int $total): int
    {
        return $total === 0 ? 0 : (int) round(($answered / $total) * 100);
    }
}
