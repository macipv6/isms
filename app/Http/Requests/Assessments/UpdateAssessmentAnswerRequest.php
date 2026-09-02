<?php

namespace App\Http\Requests\Assessments;

use App\Enums\ComplianceStatus;
use App\Models\AssessmentQuestion;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateAssessmentAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $question = $this->route('question');

        abort_unless($organization instanceof Organization, 404);
        abort_unless($project instanceof IsmsProject, 404);
        abort_unless($question instanceof AssessmentQuestion, 404);
        abort_unless($organization->organization_type === 'customer', 404);
        abort_unless($project->organization_id === $organization->id, 404);

        $assessment = $project->assessment()->first();
        abort_unless($assessment !== null, 404);
        abort_unless($question->project_assessment_id === $assessment->id, 404);

        return Gate::allows('answerAssessment', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answer' => ['present', 'nullable'],
            'compliance_status' => ['required', Rule::enum(ComplianceStatus::class)],
            'comment' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
