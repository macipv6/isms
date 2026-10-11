<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThreatCatalog extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'name', 'description'];

    /** @return HasMany<ThreatCatalogVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ThreatCatalogVersion::class, 'catalog_id');
    }
}
