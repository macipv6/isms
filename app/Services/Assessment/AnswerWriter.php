<?php

namespace App\Services\Assessment;

use App\Models\AssessmentQuestion;
use App\Models\ProjectAnswer;
use App\Models\ProjectAssessment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AnswerWriter
{
    public function __construct(
        private readonly ApplicabilityEvaluator $evaluator,
    ) {}

    public function save(
        ProjectAssessment $assessment,
        AssessmentQuestion $question,
        AnswerData $data,
        User $actor,
    ): ProjectAnswer {
        if ($question->project_assessment_id !== $assessment->id) {
            throw ValidationException::withMessages([
                'answer' => 'Die Frage gehört nicht zu dieser Bewertung.',
            ]);
        }

        if (! $this->evaluator->isApplicable(
            $question,
            $this->evaluator->answerValues($assessment),
        )) {
            throw ValidationException::withMessages([
                'answer' => 'Diese Frage ist aufgrund der aktuellen Antworten nicht anwendbar.',
            ]);
        }

        return ProjectAnswer::query()->updateOrCreate(
            [
                'project_assessment_id' => $assessment->id,
                'assessment_question_id' => $question->id,
            ],
            [
                'answer_value' => $data->answerValue,
                'answer_json' => $data->answerJson,
                'comment' => $data->comment,
                'compliance_status' => $data->complianceStatus,
                'answered_by' => $actor->id,
                'answered_at' => now(),
            ],
        );
    }
}
