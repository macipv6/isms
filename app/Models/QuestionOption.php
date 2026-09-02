<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    use HasUuids;

    protected $fillable = [
        'catalog_question_id',
        'value',
        'label',
        'score',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CatalogQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CatalogQuestion::class, 'catalog_question_id');
    }
}
