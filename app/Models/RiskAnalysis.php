<?php

namespace App\Models;

use Database\Factories\RiskAnalysisFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskAnalysis extends Model
{
    /** @use HasFactory<RiskAnalysisFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['project_id', 'business_process_id', 'asset_id', 'project_threat_id', 'scenario_identity'];

    /** @return HasMany<RiskAnalysisCycle, $this> */
    public function cycles(): HasMany
    {
        return $this->hasMany(RiskAnalysisCycle::class);
    }
}
