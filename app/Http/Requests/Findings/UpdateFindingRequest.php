<?php

namespace App\Http\Requests\Findings;

use App\Enums\FindingSeverity;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $finding = $this->nestedFinding();

        return Gate::allows('update', $finding);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'severity' => ['required', Rule::enum(FindingSeverity::class)],
        ];
    }

    private function nestedFinding(): Finding
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $finding = $this->route('finding');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $finding instanceof Finding, 404);
        abort_unless($organization->organization_type === 'customer' && $project->organization_id === $organization->id && $finding->project_id === $project->id, 404);

        return $finding;
    }
}
