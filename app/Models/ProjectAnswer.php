<?php

namespace App\Models;

use App\Enums\AnswerType;
use App\Enums\ComplianceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ComplianceStatus $compliance_status
 * @property list<string>|null $answer_json
 */
class ProjectAnswer extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_assessment_id',
        'assessment_question_id',
        'answer_value',
        'answer_json',
        'comment',
        'compliance_status',
        'answered_by',
        'answered_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'answer_json' => 'array',
            'compliance_status' => ComplianceStatus::class,
            'answered_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function valueForRules(): mixed
    {
        return match ($this->question->answer_type) {
            AnswerType::Boolean => $this->answer_value === 'true',
            AnswerType::Number => $this->numericValue(),
            AnswerType::MultipleChoice => $this->answer_json,
            AnswerType::SingleChoice, AnswerType::Text => $this->answer_value,
        };
    }

    /** @return BelongsTo<ProjectAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssessment::class, 'project_assessment_id');
    }

    /** @return BelongsTo<AssessmentQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(AssessmentQuestion::class, 'assessment_question_id');
    }

    /** @return BelongsTo<User, $this> */
    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    private function numericValue(): int|float|null
    {
        if ($this->answer_value === null) {
            return null;
        }

        return str_contains($this->answer_value, '.')
            ? (float) $this->answer_value
            : (int) $this->answer_value;
    }
}
