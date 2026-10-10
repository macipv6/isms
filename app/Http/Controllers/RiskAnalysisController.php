<?php

namespace App\Http\Controllers;

use App\Http\Requests\Risks\AssessRiskRequest;
use App\Http\Requests\Risks\RiskValidationMessages;
use App\Http\Requests\Risks\StoreRiskAnalysisRequest;
use App\Http\RiskRouteScope;
use App\Models\ProjectThreat;
use App\Models\RiskAnalysis;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskAnalysisTrigger;
use App\Services\Risks\RiskAnalysisWorkflow;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskCycleCompleter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RiskAnalysisController extends RiskController
{
    public function store(StoreRiskAnalysisRequest $request, RiskAnalysisWorkflow $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $target = RiskRouteScope::target($request, $project);
        Gate::authorize('create', [RiskAnalysisCycle::class, $project]);
        $data = $request->validated();
        $threat = ProjectThreat::query()->where('project_id', $project->id)->findOrFail($data['threat_id']);
        foreach ($data['trigger_ids'] ?? [] as $id) {
            $trigger = RiskAnalysisTrigger::query()->where('project_id', $project->id)->findOrFail($id);
            abort_unless(($trigger->business_process_id ?? $trigger->asset_id) === $target->id, 404);
        }
        $scenario = array_intersect_key($data, array_flip(['title', 'cause', 'event', 'impact', 'confidentiality', 'integrity', 'availability']));
        $service->create($project, $target, $threat, $scenario, $data['trigger_ids'] ?? [], $this->actor($request));

        return back()->with('success', 'Risikoanalyse angelegt.');
    }

    public function nextCycle(Request $request, RiskAnalysisWorkflow $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $analysis = RiskRouteScope::child($request, $project, 'analysis', RiskAnalysis::class);
        $service->startNextCycle($analysis, $this->actor($request));

        return back()->with('success', 'Neuer Analysezyklus angelegt.');
    }

    public function assess(AssessRiskRequest $request, RiskAssessmentService $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $cycle = RiskRouteScope::child($request, $project, 'cycle', RiskAnalysisCycle::class);
        Gate::authorize('update', $cycle);
        $data = $request->validated();
        if ($data['phase'] === 'inherent') {
            $service->assessInherent($cycle, $data['frequency_key'], $data['impact_key'], $data['rationale'], $this->actor($request), $data['lock_version']);
        } else {
            $service->assessResidual($cycle, $data['frequency_key'], $data['impact_key'], $data['rationale'], $this->actor($request), $data['lock_version']);
        }

        return back()->with('success', 'Risikobewertung gespeichert.');
    }

    public function complete(Request $request, RiskCycleCompleter $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $cycle = RiskRouteScope::child($request, $project, 'cycle', RiskAnalysisCycle::class);
        Gate::authorize('update', $cycle);
        $data = validator($request->all(), ['lock_version' => ['required', 'integer', 'min:0']], RiskValidationMessages::all())->validate();
        $service->complete($cycle, $this->actor($request), $data['lock_version']);

        return back()->with('success', 'Risikoanalyse abgeschlossen.');
    }
}
