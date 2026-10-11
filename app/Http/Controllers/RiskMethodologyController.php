<?php

namespace App\Http\Controllers;

use App\Http\Requests\Risks\RiskValidationMessages;
use App\Http\Requests\Risks\SaveRiskMethodologyDraftRequest;
use App\Http\RiskRouteScope;
use App\Models\RiskMethodologyVersion;
use App\Policies\ProjectRiskPolicy;
use App\Services\RiskMethodology\RiskMethodologyPublisher;
use App\Services\Risks\RiskVersionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RiskMethodologyController extends RiskController
{
    public function index(Request $request): Response
    {
        $filters = validator($request->query(), ['version_page' => ['nullable', 'integer', 'min:1']])->validate();
        $safeQuery = http_build_query($filters);
        Inertia::resolveUrlUsing(fn (Request $request): string => $request->getPathInfo().($safeQuery !== '' ? '?'.$safeQuery : ''));
        $canView = app(ProjectRiskPolicy::class)->viewGlobal($this->actor($request));
        abort_unless($canView, 403);
        $canPublish = app(ProjectRiskPolicy::class)->publishGlobal($this->actor($request));
        $query = RiskMethodologyVersion::query()->with('methodology:id,key,name')->orderByDesc('published_at');
        if (! $canPublish) {
            $query->where('status', 'published');
        }
        $versions = [];
        $page = $query->paginate(10, ['*'], 'version_page')->appends($filters);
        foreach ($page->items() as $version) {
            $versions[] = app(RiskVersionPresenter::class)->methodology($version);
        }

        return Inertia::render('risk-methodologies/Index', [
            'versions' => $versions,
            'capabilities' => ['publish' => $canPublish],
            'pagination' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()],
        ]);
    }

    public function clone(Request $request, RiskMethodologyPublisher $service): RedirectResponse
    {
        $version = RiskRouteScope::global($request, 'version', RiskMethodologyVersion::class);
        Gate::authorize('clone', $version);
        $data = validator($request->all(), ['description' => ['required', 'string', 'max:10000']], RiskValidationMessages::all())->validate();
        $service->clonePublished($version, $data['description'], $this->actor($request));

        return back()->with('success', 'Methodikentwurf angelegt.');
    }

    public function update(SaveRiskMethodologyDraftRequest $request, RiskMethodologyPublisher $service): RedirectResponse
    {
        $version = RiskRouteScope::global($request, 'version', RiskMethodologyVersion::class);
        $data = $request->validated();
        $service->replaceDraftMatrix($version, $data['levels'], $data['cells'], $this->actor($request));

        return back()->with('success', 'Methodikentwurf gespeichert.');
    }

    public function publish(Request $request, RiskMethodologyPublisher $service): RedirectResponse
    {
        $version = RiskRouteScope::global($request, 'version', RiskMethodologyVersion::class);
        Gate::authorize('publish', $version);
        $service->publish($version, $this->actor($request));

        return back()->with('success', 'Methodik veröffentlicht.');
    }
}
