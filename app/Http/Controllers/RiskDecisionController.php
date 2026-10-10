<?php

namespace App\Http\Controllers;

use App\Enums\RiskDecisionStatus;
use App\Http\Requests\Risks\StoreRiskDecisionRequest;
use App\Http\RiskRouteScope;
use App\Models\EvidenceFile;
use App\Models\RiskAnalysisCycle;
use App\Services\Risks\RiskDecisionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class RiskDecisionController extends RiskController
{
    public function store(StoreRiskDecisionRequest $request, RiskDecisionWorkflow $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $cycle = RiskRouteScope::child($request, $project, 'cycle', RiskAnalysisCycle::class);
        $data = $request->validated();
        $status = RiskDecisionStatus::from($data['status']);
        if ($data['channel'] === 'external') {
            Gate::authorize('decideExternal', $cycle);
            $evidence = isset($data['evidence_id']) ? EvidenceFile::query()->where('project_id', $project->id)->findOrFail($data['evidence_id']) : null;
            $service->recordExternal($cycle, $status, $data['name'], $data['function'], $data['decision_date'], $data['rationale'], $evidence, $this->actor($request));
        } else {
            Gate::authorize('decideInternal', $cycle);
            $service->decideInternal($cycle, $status, $data['rationale'], $data['decision_date'], $this->actor($request));
        }

        return back()->with('success', 'Risikoentscheidung dokumentiert.');
    }
}
