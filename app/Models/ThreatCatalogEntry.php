<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreatCatalogEntry extends Model
{
    use HasUuids;

    protected $fillable = ['version_id', 'code', 'title', 'description', 'confidentiality', 'integrity', 'availability', 'sort_order', 'source_edition', 'source_reference'];

    protected function casts(): array
    {
        return ['confidentiality' => 'boolean', 'integrity' => 'boolean', 'availability' => 'boolean'];
    }

    /** @return BelongsTo<ThreatCatalogVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ThreatCatalogVersion::class, 'version_id');
    }
}
