<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'framework' => ['required', Rule::in(['BSI'])],
            'approach' => ['required', Rule::in(['basis_absicherung'])],
            'bcm_level' => ['required', Rule::in(['aufbau_bcms'])],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'scope_text' => ['nullable', 'string', 'max:20000'],
            'started_at' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:started_at'],
            'completed_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'organization_id' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }
}
