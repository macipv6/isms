<?php

namespace App\Http\Requests\Risks;

use Illuminate\Validation\Rule;

class StoreRiskAnalysisRequest extends RiskFormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $projectId = $this->project()->id;

        return [
            'threat_id' => ['required', 'uuid', Rule::exists('project_threats', 'id')->where('project_id', $projectId)],
            'title' => ['required', 'string', 'max:255'], 'cause' => ['required', 'string', 'max:10000'],
            'event' => ['required', 'string', 'max:10000'], 'impact' => ['required', 'string', 'max:10000'],
            'confidentiality' => ['required', 'boolean'], 'integrity' => ['required', 'boolean'], 'availability' => ['required', 'boolean'],
            'trigger_ids' => ['required', 'array', 'min:1', 'max:100'],
            'trigger_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('risk_analysis_triggers', 'id')->where('project_id', $projectId)],
        ];
    }
}
