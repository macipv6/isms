<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'catalog_version_id',
        'key',
        'name',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
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
     * @return HasMany<CatalogQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(CatalogQuestion::class)->orderBy('sort_order');
    }
}
