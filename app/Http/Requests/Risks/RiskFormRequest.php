<?php

namespace App\Http\Requests\Risks;

use App\Http\RiskRouteScope;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\ProjectThreat;
use App\Models\RiskAnalysisTrigger;
use App\Models\User;
use App\Policies\ProjectRiskPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

abstract class RiskFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->project();
        $user = $this->user();
        foreach (['threat_id' => ProjectThreat::class, 'evidence_id' => EvidenceFile::class, 'cessation_evidence_id' => EvidenceFile::class] as $field => $class) {
            $id = $this->input($field);
            if (is_string($id) && Str::isUuid($id)) {
                $class::query()->where('project_id', $project->id)->findOrFail($id);
            }
        }
        $authorized = $user instanceof User && app(ProjectRiskPolicy::class)->write($user, $project);
        if (! $authorized) {
            $this->assertScopedTriggerIds($project);
        }

        return $authorized;
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->assertScopedTriggerIds($this->project());
        parent::failedValidation($validator);
    }

    private function assertScopedTriggerIds(IsmsProject $project): void
    {
        $triggerIds = $this->input('trigger_ids');
        if (! is_array($triggerIds) || count($triggerIds) > 100) {
            return;
        }
        $ids = array_values(array_unique(array_filter($triggerIds, fn (mixed $id): bool => is_string($id) && Str::isUuid($id))));
        if ($ids !== [] && RiskAnalysisTrigger::query()->where('project_id', $project->id)->whereIn('id', $ids)->count() !== count($ids)) {
            abort(404);
        }
    }

    protected function project(): IsmsProject
    {
        [, $project] = RiskRouteScope::project($this);

        return $project;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return RiskValidationMessages::all();
    }
}
