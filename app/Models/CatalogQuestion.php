<?php

namespace App\Models;

use App\Enums\AnswerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogQuestion extends Model
{
    use HasUuids;

    protected $fillable = [
        'catalog_version_id',
        'question_category_id',
        'question_key',
        'title',
        'question_text',
        'help_text',
        'answer_type',
        'severity',
        'evidence_expected',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'answer_type' => AnswerType::class,
            'evidence_expected' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CatalogVersion, $this>
     */
    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogVersion::class);
    }

    /**
     * @return BelongsTo<QuestionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(QuestionCategory::class, 'question_category_id');
    }

    /**
     * @return HasMany<QuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<QuestionRule, $this>
     */
    public function outgoingRules(): HasMany
    {
        return $this->hasMany(QuestionRule::class, 'trigger_question_id');
    }

    /**
     * @return HasMany<QuestionRule, $this>
     */
    public function incomingRules(): HasMany
    {
        return $this->hasMany(QuestionRule::class, 'target_question_id');
    }
}
