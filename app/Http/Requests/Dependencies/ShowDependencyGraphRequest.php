<?php

namespace App\Http\Requests\Dependencies;

use App\Enums\DependencyNodeType;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ShowDependencyGraphRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->route('type');
        abort_unless(is_string($type) && DependencyNodeType::tryFrom($type) !== null, 404);

        return Gate::allows('viewGraph', [DependencyEdge::class, $this->project()]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'direction' => ['sometimes', Rule::in(['dependencies', 'dependents', 'affected_processes'])],
            'transitive' => ['sometimes', Rule::in(['true', 'false'])],
            'include_inactive' => ['sometimes', Rule::in(['true', 'false'])],
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
