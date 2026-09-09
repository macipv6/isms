<?php

namespace App\Http\Requests\Measures;

use App\Enums\MeasurePriority;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateMeasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $measure = $this->nestedMeasure();

        return Gate::allows('update', $measure);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['required', Rule::enum(MeasurePriority::class)],
            'responsible_name' => ['required', 'string', 'max:255'],
            'responsible_email' => ['nullable', 'email', 'max:255'],
            'due_date' => ['required', 'date_format:Y-m-d'],
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
