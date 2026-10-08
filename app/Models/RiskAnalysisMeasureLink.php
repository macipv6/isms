<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RiskAnalysisMeasureLink extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'risk_analysis_cycle_id', 'measure_id'];
}
