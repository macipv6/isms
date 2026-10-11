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
        $filters = validator($request->query(), ['version_page' => ['nullable', 'integer', 'min:1'], 'entry_page' => ['nullable', 'integer', 'min:1']])->validate();
        $safeQuery = http_build_query($filters);
        Inertia::resolveUrlUsing(fn (Request $request): string => $request->getPathInfo().($safeQuery !== '' ? '?'.$safeQuery : ''));
        abort_unless(app(ProjectRiskPolicy::class)->viewGlobal($this->actor($request)), 403);
        $canPublish = app(ProjectRiskPolicy::class)->publishGlobal($this->actor($request));
        $query = ThreatCatalogVersion::query()->with('catalog:id,key,name')->orderByDesc('published_at');
        if (! $canPublish) {
            $query->where('status', 'published');
        }
        $versions = [];
        $page = $query->paginate(10, ['*'], 'version_page')->appends($filters);
        foreach ($page->items() as $version) {
            $versions[] = app(\App\Services\Risks\RiskVersionPresenter::class)->catalog($version, true, (int) ($filters['entry_page'] ?? 1));
        }

        return Inertia::render('threat-catalogs/Index', ['versions' => $versions, 'capabilities' => ['publish' => $canPublish], 'versionPage' => $page->currentPage(), 'pagination' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()]]);
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
