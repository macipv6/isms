<?php

namespace App\Http\Requests\Evidence;

use App\Models\AssessmentQuestion;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        [$organization, $project, $question] = $this->nestedQuestion();
        abort_unless($organization->organization_type === 'customer' && $project->organization_id === $organization->id, 404);
        $assessment = $project->assessment()->first();
        abort_unless($assessment !== null && $question->project_assessment_id === $assessment->id, 404);

        return Gate::allows('upload', [EvidenceFile::class, $project]);
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file']];
    }

    /** @return array{Organization, IsmsProject, AssessmentQuestion} */
    private function nestedQuestion(): array
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $question = $this->route('question');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $question instanceof AssessmentQuestion, 404);

        return [$organization, $project, $question];
    }
}
