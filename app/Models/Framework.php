<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Framework extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CatalogVersion, $this>
     */
    public function catalogVersions(): HasMany
    {
        return $this->hasMany(CatalogVersion::class);
    }
}
