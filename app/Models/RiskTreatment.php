<?php

namespace App\Models;

use App\Enums\RiskTreatmentAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property RiskTreatmentAction $action */
class RiskTreatment extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'risk_analysis_cycle_id', 'action', 'rationale', 'counterparty_name', 'counterparty_email', 'cessation_statement', 'selected_by'];

    protected function casts(): array
    {
        return ['action' => RiskTreatmentAction::class];
    }
}
