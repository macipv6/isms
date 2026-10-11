<?php

namespace App\Http\Controllers;

use App\Enums\RiskTriggerType;
use App\Http\Requests\Risks\StoreRiskTriggerRequest;
use App\Http\RiskRouteScope;
use App\Models\RiskAnalysisTrigger;
use App\Services\Risks\RiskTriggerEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RiskTriggerController extends RiskController
{
    public function store(StoreRiskTriggerRequest $request, RiskTriggerEvaluator $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $data = $request->validated();
        $service->register($project, RiskRouteScope::target($request, $project), RiskTriggerType::from($data['type']), $data['rationale'], $this->actor($request));

        return back()->with('success', 'Auslöser angelegt.');
    }

    public function dismiss(Request $request, RiskTriggerEvaluator $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $trigger = RiskRouteScope::child($request, $project, 'trigger', RiskAnalysisTrigger::class);
        $service->dismiss($trigger, $this->actor($request));

        return back()->with('success', 'Auslöser zurückgenommen.');
    }
}
