<?php

namespace App\Http\Controllers;

use App\Http\Requests\Risks\StoreProjectThreatRequest;
use App\Http\RiskRouteScope;
use App\Models\ProjectThreat;
use App\Services\Threats\ProjectThreatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProjectThreatController extends RiskController
{
    public function store(StoreProjectThreatRequest $request, ProjectThreatService $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        Gate::authorize('create', [ProjectThreat::class, $project]);
        $service->createCustom($project, $request->validated(), $this->actor($request));

        return back()->with('success', 'Gefährdung angelegt.');
    }

    public function update(StoreProjectThreatRequest $request, ProjectThreatService $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $threat = RiskRouteScope::child($request, $project, 'threat', ProjectThreat::class);
        Gate::authorize('update', $threat);
        $service->updateCustom($threat, $request->validated(), $this->actor($request));

        return back()->with('success', 'Gefährdung aktualisiert.');
    }
}
