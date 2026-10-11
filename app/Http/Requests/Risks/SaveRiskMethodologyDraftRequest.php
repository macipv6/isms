<?php

namespace App\Http\Requests\Risks;

use App\Http\RiskRouteScope;
use App\Models\RiskMethodologyVersion;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveRiskMethodologyDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = RiskRouteScope::global($this, 'version', RiskMethodologyVersion::class);

        return $this->user() instanceof User && Gate::forUser($this->user())->allows('update', $version);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'levels' => ['required', 'array:frequency,impact,risk'],
            'levels.frequency' => ['required_with:levels', 'array', 'min:1', 'max:20'],
            'levels.impact' => ['required_with:levels', 'array', 'min:1', 'max:20'],
            'levels.risk' => ['required_with:levels', 'array', 'min:1', 'max:20'],
            'levels.*.*.key' => ['required_with:levels', 'string', 'max:64'],
            'levels.*.*.label' => ['required_with:levels', 'string', 'max:255'],
            'levels.*.*.description' => ['nullable', 'string', 'max:10000'],
            'levels.*.*.sort_order' => ['required_with:levels', 'integer', 'min:0', 'max:1000'],
            'cells' => ['required_with:levels', 'array', 'max:400'],
            'cells.*.frequency_key' => ['required_with:cells', 'string', 'max:64'],
            'cells.*.impact_key' => ['required_with:cells', 'string', 'max:64'],
            'cells.*.risk_key' => ['required_with:cells', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return RiskValidationMessages::all();
    }
}
