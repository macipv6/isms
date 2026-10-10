<?php

namespace App\Http\Requests\Risks;

use App\Enums\RiskDecisionStatus;
use App\Http\RiskRouteScope;
use App\Models\RiskAnalysisCycle;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreRiskDecisionRequest extends RiskFormRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        $cycle = RiskRouteScope::child($this, $this->project(), 'cycle', RiskAnalysisCycle::class);

        return Gate::forUser($this->user())->allows($this->input('channel') === 'external' ? 'decideExternal' : 'decideInternal', $cycle);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['internal', 'external'])],
            'status' => ['required', Rule::enum(RiskDecisionStatus::class)],
            'rationale' => ['required', 'string', 'max:10000'], 'decision_date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required_if:channel,external', 'nullable', 'string', 'max:255'],
            'function' => ['required_if:channel,external', 'nullable', 'string', 'max:255'],
            'evidence_id' => ['nullable', 'uuid', Rule::exists('evidence_files', 'id')->where('project_id', $this->project()->id)],
        ];
    }
}
