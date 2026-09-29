<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskMatrixCell extends Model
{
    use HasUuids;

    protected $fillable = ['version_id', 'frequency_level_id', 'impact_level_id', 'risk_level_id'];

    /** @return BelongsTo<RiskMethodologyVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(RiskMethodologyVersion::class, 'version_id');
    }

    /** @return BelongsTo<RiskFrequencyLevel, $this> */
    public function frequency(): BelongsTo
    {
        return $this->belongsTo(RiskFrequencyLevel::class, 'frequency_level_id');
    }

    /** @return BelongsTo<RiskImpactLevel, $this> */
    public function impact(): BelongsTo
    {
        return $this->belongsTo(RiskImpactLevel::class, 'impact_level_id');
    }

    /** @return BelongsTo<RiskLevel, $this> */
    public function riskLevel(): BelongsTo
    {
        return $this->belongsTo(RiskLevel::class, 'risk_level_id');
    }
}
