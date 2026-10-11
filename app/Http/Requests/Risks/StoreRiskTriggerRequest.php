<?php

namespace App\Http\Requests\Risks;

use Illuminate\Validation\Rule;

class StoreRiskTriggerRequest extends RiskFormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['manual', 'nonstandard_scenario'])], 'rationale' => ['required', 'string', 'max:10000']];
    }
}
