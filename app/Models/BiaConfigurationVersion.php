<?php

namespace App\Models;

use App\Enums\BcmRevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<int> $minutes
 * @property BcmRevisionStatus $status
 * @property CarbonImmutable $approved_at
 */
class BiaConfigurationVersion extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'version', 'lock_version', 'minutes', 'status', 'created_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['minutes' => 'array', 'status' => BcmRevisionStatus::class, 'approved_at' => 'immutable_datetime', 'version' => 'integer', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class, 'project_id');
    }
}
