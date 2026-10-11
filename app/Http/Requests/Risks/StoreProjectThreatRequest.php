<?php

namespace App\Http\Requests\Risks;

class StoreProjectThreatRequest extends RiskFormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]*$/'],
            'title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:10000'],
            'addition_rationale' => ['required', 'string', 'max:10000'],
            'confidentiality' => ['required', 'boolean'], 'integrity' => ['required', 'boolean'], 'availability' => ['required', 'boolean'],
            'responsible_name' => ['required', 'string', 'max:160'], 'responsible_email' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }
}
