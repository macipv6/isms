<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BusinessProcessFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $key
 * @property string $name
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class BusinessProcess extends Model
{
    /** @use HasFactory<BusinessProcessFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id', 'key', 'name', 'description', 'owner_name', 'owner_email', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
