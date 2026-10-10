<?php

namespace App\Http\Controllers;

use App\Http\Requests\Risks\SaveThreatCatalogDraftRequest;
use App\Http\RiskRouteScope;
use App\Models\ThreatCatalogVersion;
use App\Policies\ProjectRiskPolicy;
use App\Services\Threats\ThreatCatalogPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ThreatCatalogController extends RiskController
{
    public function index(Request $request): Response
    {
        abort_unless(app(ProjectRiskPolicy::class)->viewGlobal($this->actor($request)), 403);
        $canPublish = app(ProjectRiskPolicy::class)->publishGlobal($this->actor($request));
        $query = ThreatCatalogVersion::query()->with('catalog:id,key,name')->orderByDesc('published_at');
        if (! $canPublish) {
            $query->where('status', 'published');
        }
        $versions = [];
        foreach ($query->limit(100)->get() as $version) {
            $versions[] = [
                'id' => $version->id, 'catalogId' => $version->catalog_id,
                'name' => $version->catalog->name, 'version' => $version->version,
                'status' => $version->status->value, 'description' => mb_substr((string) $version->description, 0, 10000),
                'publishedAt' => $version->published_at ? (string) $version->published_at : null,
            ];
        }

        return Inertia::render('threat-catalogs/Index', ['versions' => $versions, 'capabilities' => ['publish' => $canPublish]]);
    }

    public function clone(Request $request, ThreatCatalogPublisher $service): RedirectResponse
    {
        $version = RiskRouteScope::global($request, 'version', ThreatCatalogVersion::class);
        Gate::authorize('clone', $version);
        $service->clonePublished($version, $this->actor($request));

        return back()->with('success', 'Katalogentwurf angelegt.');
    }

    public function update(SaveThreatCatalogDraftRequest $request, ThreatCatalogPublisher $service): RedirectResponse
    {
        $version = RiskRouteScope::global($request, 'version', ThreatCatalogVersion::class);
        $service->upsertDraftEntry($version, $request->validated(), $this->actor($request));

        return back()->with('success', 'Katalogeintrag gespeichert.');
    }

    public function publish(Request $request, ThreatCatalogPublisher $service): RedirectResponse
    {
        $version = RiskRouteScope::global($request, 'version', ThreatCatalogVersion::class);
        Gate::authorize('publish', $version);
        $service->publish($version, $this->actor($request));

        return back()->with('success', 'Gefährdungskatalog veröffentlicht.');
    }
}
