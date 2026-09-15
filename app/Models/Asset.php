<?php

namespace App\Models;

use App\Enums\AssetType;
use Carbon\CarbonImmutable;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AssetType $type
 * @property string $key
 * @property string $name
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id', 'key', 'name', 'type', 'description', 'owner_name', 'owner_email', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['type' => AssetType::class, 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
