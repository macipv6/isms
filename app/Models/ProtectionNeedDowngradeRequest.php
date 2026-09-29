<?php

namespace App\Models;

use App\Enums\DowngradeRequestStatus;
use App\Enums\ProtectionLevel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ProtectionLevel $inherited_level
 * @property ProtectionLevel $requested_level
 * @property DowngradeRequestStatus $status
 * @property string|null $decision_channel
 */
class ProtectionNeedDowngradeRequest extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'protection_need_id', 'inherited_level', 'requested_level', 'rationale', 'requested_by', 'status', 'decision_channel', 'decided_by', 'recorded_by', 'external_name', 'external_function', 'external_decision_date', 'decision_rationale', 'external_evidence_id', 'decided_at'];

    protected function casts(): array
    {
        return ['inherited_level' => ProtectionLevel::class, 'requested_level' => ProtectionLevel::class, 'status' => DowngradeRequestStatus::class, 'external_decision_date' => 'immutable_date', 'decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ProtectionNeed, $this> */
    public function need(): BelongsTo
    {
        return $this->belongsTo(ProtectionNeed::class, 'protection_need_id');
    }
}
