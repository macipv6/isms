<?php

namespace App\Http\Controllers;

use App\Http\Requests\Risks\InitializeRiskContextRequest;
use App\Http\Requests\Risks\MigrateRiskContextRequest;
use App\Http\RiskRouteScope;
use App\Models\ProjectRiskContext;
use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;
use App\Services\Threats\ProjectRiskContextService;
use Illuminate\Http\RedirectResponse;

class ProjectRiskContextController extends RiskController
{
    public function initialize(InitializeRiskContextRequest $request, ProjectRiskContextService $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $data = $request->validated();
        $service->initialize($project, RiskMethodologyVersion::query()->findOrFail($data['methodology_version_id']), ThreatCatalogVersion::query()->findOrFail($data['catalog_version_id']), $this->actor($request));

        return back()->with('success', 'Risikokontext initialisiert.');
    }

    public function migrate(MigrateRiskContextRequest $request, ProjectRiskContextService $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $context = RiskRouteScope::child($request, $project, 'context', ProjectRiskContext::class);
        $data = $request->validated();
        $service->migrate($context, RiskMethodologyVersion::query()->findOrFail($data['methodology_version_id']), ThreatCatalogVersion::query()->findOrFail($data['catalog_version_id']), $this->actor($request));

        return back()->with('success', 'Risikokontext migriert.');
    }
}
