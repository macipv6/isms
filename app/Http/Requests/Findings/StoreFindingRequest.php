<?php

namespace App\Http\Requests\Findings;

use App\Enums\FindingSeverity;
use App\Models\AssessmentQuestion;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        [$organization, $project, $question] = $this->nestedResources();
        abort_unless($organization->organization_type === 'customer' && $project->organization_id === $organization->id, 404);
        $assessment = $project->assessment()->first();
        abort_unless($assessment !== null && $question->project_assessment_id === $assessment->id, 404);

        return Gate::allows('propose', [Finding::class, $project]);
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

    /** @return array{Organization, IsmsProject, AssessmentQuestion} */
    private function nestedResources(): array
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $question = $this->route('question');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $question instanceof AssessmentQuestion, 404);

        return [$organization, $project, $question];
    }
}
