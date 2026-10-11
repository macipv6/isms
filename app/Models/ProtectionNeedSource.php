<?php

namespace App\Models;

use App\Enums\ProtectionLevel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property ProtectionLevel $origin_level */
class ProtectionNeedSource extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'protection_need_id', 'origin_process_id', 'origin_asset_id', 'origin_level', 'first_edge_id'];

    protected function casts(): array
    {
        return ['origin_level' => ProtectionLevel::class];
    }

    /** @return BelongsTo<ProtectionNeed, $this> */
    public function need(): BelongsTo
    {
        return $this->belongsTo(ProtectionNeed::class, 'protection_need_id');
    }
}
