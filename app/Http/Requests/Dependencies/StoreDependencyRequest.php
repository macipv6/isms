<?php

namespace App\Http\Requests\Dependencies;

use App\Enums\DependencyImportance;
use App\Enums\DependencyNodeType;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [DependencyEdge::class, $this->project()]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'source_type' => ['required', Rule::enum(DependencyNodeType::class)],
            'source_key' => ['required', 'string'],
            'target_type' => ['required', Rule::enum(DependencyNodeType::class)],
            'target_key' => ['required', 'string'],
            'importance' => ['required', Rule::enum(DependencyImportance::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function project(): IsmsProject
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $organization->organization_type === 'customer' && $project->organization_id === $organization->id, 404);

        return $project;
    }
}
