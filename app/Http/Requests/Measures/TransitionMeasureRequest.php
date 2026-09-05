<?php

namespace App\Http\Requests\Measures;

use App\Enums\MeasureStatus;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TransitionMeasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $measure = $this->nestedMeasure();

        return Gate::allows('transition', $measure);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(MeasureStatus::class)],
            'reason' => ['nullable', 'string', 'max:10000', 'required_if:status,'.MeasureStatus::Cancelled->value],
        ];
    }

    private function nestedMeasure(): Measure
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $measure = $this->route('measure');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $measure instanceof Measure, 404);
        abort_unless(
            $organization->organization_type === 'customer'
                && $project->organization_id === $organization->id
                && $measure->project_id === $project->id,
            404,
        );

        return $measure;
    }
}
