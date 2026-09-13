<?php

namespace App\Http\Requests\Dependencies;

use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ChangeDependencyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('changeStatus', $this->dependency());
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
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
