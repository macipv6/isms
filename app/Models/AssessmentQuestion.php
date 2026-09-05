<?php

namespace App\Models;

use App\Enums\AnswerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property AnswerType $answer_type
 * @property list<array{value: string, label: string, score: int|null, sort_order: int}> $options
 * @property list<array{trigger_question_key: string, operator: string, expected_value: mixed, action: string}> $rules
 */
class AssessmentQuestion extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_assessment_id',
        'source_question_id',
        'question_key',
        'category_key',
        'category_name',
        'category_sort_order',
        'title',
        'question_text',
        'help_text',
        'answer_type',
        'severity',
        'evidence_expected',
        'is_active',
        'question_sort_order',
        'options',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'answer_type' => AnswerType::class,
            'evidence_expected' => 'boolean',
            'is_active' => 'boolean',
            'category_sort_order' => 'integer',
            'question_sort_order' => 'integer',
            'options' => 'array',
            'rules' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ProjectAssessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssessment::class, 'project_assessment_id');
    }

    /**
     * @return BelongsTo<CatalogQuestion, $this>
     */
    public function sourceQuestion(): BelongsTo
    {
        return $this->belongsTo(CatalogQuestion::class, 'source_question_id');
    }

    /**
     * @return HasOne<ProjectAnswer, $this>
     */
    public function answer(): HasOne
    {
        return $this->hasOne(ProjectAnswer::class, 'assessment_question_id');
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'assessment_question_id');
    }

    /**
     * @return BelongsToMany<EvidenceFile, $this, EvidenceQuestionLink>
     */
    public function evidenceFiles(): BelongsToMany
    {
        return $this->belongsToMany(EvidenceFile::class, 'evidence_question_links')
            ->using(EvidenceQuestionLink::class)
            ->withPivot('project_id', 'project_assessment_id')
            ->withTimestamps();
    }
}
