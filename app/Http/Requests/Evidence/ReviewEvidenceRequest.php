<?php

namespace App\Http\Requests\Evidence;

use App\Enums\EvidenceReviewStatus;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReviewEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $evidence = $this->route('evidence');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $evidence instanceof EvidenceFile, 404);
        abort_unless($organization->organization_type === 'customer' && $project->organization_id === $organization->id && $evidence->project_id === $project->id, 404);

        return Gate::allows('review', $evidence);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(EvidenceReviewStatus::class), Rule::in([EvidenceReviewStatus::Verified->value, EvidenceReviewStatus::Rejected->value])],
            'review_note' => ['nullable', 'string', 'max:10000', 'required_if:status,'.EvidenceReviewStatus::Rejected->value],
        ];
    }
}
