<?php

namespace App\Http\Requests\Risks;

use Illuminate\Validation\Rule;

class InitializeRiskContextRequest extends RiskFormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'methodology_version_id' => ['required', 'uuid', Rule::exists('risk_methodology_versions', 'id')->where('status', 'published')],
            'catalog_version_id' => ['required', 'uuid', Rule::exists('threat_catalog_versions', 'id')->where('status', 'published')],
        ];
    }
}
