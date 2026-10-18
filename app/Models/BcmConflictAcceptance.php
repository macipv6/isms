<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $fingerprint
 * @property string $accepted_by
 * @property CarbonImmutable $accepted_at
 * @property array<string, mixed> $warning_snapshot
 */
class BcmConflictAcceptance extends Model
{
    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['project_id', 'bia_revision_id', 'fingerprint', 'warning_code', 'warning_snapshot', 'reason', 'accepted_by', 'accepted_at'];

    protected function casts(): array
    {
        return ['warning_snapshot' => 'array', 'accepted_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BiaRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(BiaRevision::class, 'bia_revision_id');
    }
}
