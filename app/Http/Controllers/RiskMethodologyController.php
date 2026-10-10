<?php

namespace App\Http\Controllers;

use App\Http\Requests\Risks\RiskValidationMessages;
use App\Http\Requests\Risks\SaveRiskMethodologyDraftRequest;
use App\Http\RiskRouteScope;
use App\Models\RiskMethodologyVersion;
use App\Policies\ProjectRiskPolicy;
use App\Services\RiskMethodology\RiskMethodologyPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RiskMethodologyController extends RiskController
{
    public function index(Request $request): Response
    {
        $canView = app(ProjectRiskPolicy::class)->viewGlobal($this->actor($request));
        abort_unless($canView, 403);
        $canPublish = app(ProjectRiskPolicy::class)->publishGlobal($this->actor($request));
        $query = RiskMethodologyVersion::query()->with('methodology:id,key,name')->orderByDesc('published_at');
        if (! $canPublish) {
            $query->where('status', 'published');
        }
        $versions = [];
        foreach ($query->limit(100)->get() as $version) {
            $versions[] = [
                'id' => $version->id, 'methodologyId' => $version->methodology_id,
                'name' => $version->methodology->name, 'version' => $version->version,
                'status' => $version->status->value, 'description' => mb_substr((string) $version->description, 0, 10000),
                'publishedAt' => $version->published_at ? (string) $version->published_at : null,
            ];
        }

        return Inertia::render('risk-methodologies/Index', [
            'versions' => $versions,
            'capabilities' => ['publish' => $canPublish],
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
