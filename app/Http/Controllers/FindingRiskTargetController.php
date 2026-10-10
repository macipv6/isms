<?php

namespace App\Http\Controllers;

use App\Http\RiskRouteScope;
use App\Models\Finding;
use App\Services\Risks\FindingRiskTargetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FindingRiskTargetController extends RiskController
{
    public function store(Request $request, FindingRiskTargetService $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $finding = RiskRouteScope::child($request, $project, 'finding', Finding::class);
        $target = RiskRouteScope::target($request, $project);
        $service->link($finding, $target, $this->actor($request));

        return back()->with('success', 'Fund mit Risikoziel verknüpft.');
    }
}
