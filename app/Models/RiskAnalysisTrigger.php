<?php

namespace App\Models;

use App\Enums\RiskTriggerStatus;
use App\Enums\RiskTriggerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property RiskTriggerType $type
 * @property RiskTriggerStatus $status
 */
class RiskAnalysisTrigger extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'business_process_id', 'asset_id', 'type', 'source_identity', 'source_finding_id', 'rationale', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['type' => RiskTriggerType::class, 'status' => RiskTriggerStatus::class];
    }
}
