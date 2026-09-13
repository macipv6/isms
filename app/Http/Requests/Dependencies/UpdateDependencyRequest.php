<?php

namespace App\Http\Requests\Dependencies;

use App\Enums\DependencyImportance;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Rules\Iso8601ConcurrencyToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->dependency());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'importance' => ['required', Rule::enum(DependencyImportance::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'updated_at' => ['required', new Iso8601ConcurrencyToken],
            'source_type' => ['prohibited'],
            'source_key' => ['prohibited'],
            'target_type' => ['prohibited'],
            'target_key' => ['prohibited'],
        ];
    }

    private function dependency(): DependencyEdge
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $dependency = $this->route('dependency');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $dependency instanceof DependencyEdge && $organization->organization_type === 'customer' && $project->organization_id === $organization->id && $dependency->project_id === $project->id, 404);

        return $dependency;
    }
}
