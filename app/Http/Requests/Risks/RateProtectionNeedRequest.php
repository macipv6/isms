<?php

namespace App\Http\Requests\Risks;

use App\Enums\ProtectionLevel;
use Illuminate\Validation\Rule;

class RateProtectionNeedRequest extends RiskFormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'ratings' => ['required', 'array', 'min:1'],
            'ratings.*.level' => ['required', Rule::enum(ProtectionLevel::class)],
            'ratings.*.rationale' => ['required', 'string', 'max:10000'],
            'expected_versions' => ['required', 'array:confidentiality,integrity,availability'],
            'expected_versions.confidentiality' => ['required', 'integer', 'min:0'],
            'expected_versions.integrity' => ['required', 'integer', 'min:0'],
            'expected_versions.availability' => ['required', 'integer', 'min:0'],
        ];
    }
}
