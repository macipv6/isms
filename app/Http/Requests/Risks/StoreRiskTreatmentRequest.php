<?php

namespace App\Http\Requests\Risks;

use App\Enums\RiskTreatmentAction;
use Illuminate\Validation\Rule;

class StoreRiskTreatmentRequest extends RiskFormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(RiskTreatmentAction::class)], 'rationale' => ['required', 'string', 'max:10000'],
            'counterparty_name' => ['required_if:action,transfer', 'nullable', 'string', 'max:255'],
            'counterparty_email' => ['nullable', 'email:rfc', 'max:255'],
            'cessation_statement' => ['required_if:action,avoid', 'nullable', 'string', 'max:10000'],
            'cessation_evidence_id' => ['required_if:action,avoid', 'nullable', 'uuid', Rule::exists('evidence_files', 'id')->where('project_id', $this->project()->id)],
        ];
    }
}
