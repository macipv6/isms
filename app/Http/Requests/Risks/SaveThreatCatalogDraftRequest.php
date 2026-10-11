<?php

namespace App\Http\Requests\Risks;

use App\Http\RiskRouteScope;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveThreatCatalogDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = RiskRouteScope::global($this, 'version', ThreatCatalogVersion::class);

        return $this->user() instanceof User && Gate::forUser($this->user())->allows('update', $version);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'], 'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'confidentiality' => ['required', 'boolean'], 'integrity' => ['required', 'boolean'], 'availability' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'source_edition' => ['nullable', 'string', 'max:255'], 'source_reference' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return RiskValidationMessages::all();
    }
}
