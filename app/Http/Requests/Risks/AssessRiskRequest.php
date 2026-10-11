<?php

namespace App\Http\Requests\Risks;

use Illuminate\Validation\Rule;

class AssessRiskRequest extends RiskFormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'phase' => ['required', Rule::in(['inherent', 'residual'])],
            'frequency_key' => ['required', 'string', 'max:64'], 'impact_key' => ['required', 'string', 'max:64'],
            'rationale' => ['required', 'string', 'max:10000'], 'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
