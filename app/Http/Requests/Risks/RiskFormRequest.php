<?php

namespace App\Http\Requests\Risks;

use App\Http\RiskRouteScope;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\ProjectThreat;
use App\Models\RiskAnalysisTrigger;
use App\Models\User;
use App\Policies\ProjectRiskPolicy;
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
        $triggerIds = $this->input('trigger_ids');
        if (is_array($triggerIds)) {
            foreach ($triggerIds as $id) {
                if (is_string($id) && Str::isUuid($id)) {
                    RiskAnalysisTrigger::query()->where('project_id', $project->id)->findOrFail($id);
                }
            }
        }

        return $user instanceof User && app(ProjectRiskPolicy::class)->write($user, $project);
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
