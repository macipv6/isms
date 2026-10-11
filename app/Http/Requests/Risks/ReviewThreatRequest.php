<?php

namespace App\Http\Requests\Risks;

use Illuminate\Validation\Rule;

class ReviewThreatRequest extends RiskFormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['applicable', 'not_applicable'])], 'rationale' => ['required_if:status,not_applicable', 'nullable', 'string', 'max:10000']];
    }
}
