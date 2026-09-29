<?php

namespace App\Models;

use App\Enums\PublicationStatus;
use Database\Factories\ThreatCatalogVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property PublicationStatus $status */
class ThreatCatalogVersion extends Model
{
    /** @use HasFactory<ThreatCatalogVersionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['catalog_id', 'version', 'status', 'description', 'source_edition', 'source_reference', 'published_at', 'published_by'];

    protected function casts(): array
    {
        return ['status' => PublicationStatus::class, 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ThreatCatalog, $this> */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ThreatCatalog::class, 'catalog_id');
    }

    /** @return HasMany<ThreatCatalogEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ThreatCatalogEntry::class, 'version_id')->orderBy('sort_order');
    }
}
