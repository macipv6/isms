<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskMethodology extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'name', 'description'];

    /** @return HasMany<RiskMethodologyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RiskMethodologyVersion::class, 'methodology_id');
    }
}
