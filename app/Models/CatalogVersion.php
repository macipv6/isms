<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'framework_id',
        'version',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    public static function publishedForFramework(string $frameworkKey): self
    {
        return self::query()
            ->where('status', CatalogStatus::Published->value)
            ->whereHas(
                'framework',
                fn (Builder $query): Builder => $query
                    ->where('key', $frameworkKey)
                    ->where('is_active', true),
            )
            ->latest('published_at')
            ->firstOrFail();
    }

    /**
     * @return BelongsTo<Framework, $this>
     */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(Framework::class);
    }

    /**
     * @return HasMany<QuestionCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(QuestionCategory::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<CatalogQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(CatalogQuestion::class)->orderBy('sort_order');
    }
}
