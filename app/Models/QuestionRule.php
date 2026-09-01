<?php

namespace App\Models;

use App\Enums\RuleAction;
use App\Enums\RuleOperator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'catalog_version_id',
        'trigger_question_id',
        'target_question_id',
        'operator',
        'expected_value',
        'action',
    ];

    protected function casts(): array
    {
        return [
            'operator' => RuleOperator::class,
            'expected_value' => 'json',
            'action' => RuleAction::class,
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
     * @return BelongsTo<CatalogQuestion, $this>
     */
    public function triggerQuestion(): BelongsTo
    {
        return $this->belongsTo(CatalogQuestion::class, 'trigger_question_id');
    }

    /**
     * @return BelongsTo<CatalogQuestion, $this>
     */
    public function targetQuestion(): BelongsTo
    {
        return $this->belongsTo(CatalogQuestion::class, 'target_question_id');
    }
}
