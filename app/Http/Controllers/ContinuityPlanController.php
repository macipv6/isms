<?php

namespace App\Http\Controllers;

use App\Http\BcmRouteScope;
use App\Models\ContinuityPlanRevision;
use App\Services\Bcm\ContinuityPlanPresenter;
use App\Services\Bcm\ContinuityPlanWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContinuityPlanController extends Controller
{
    public function index(Request $request, ContinuityPlanPresenter $presenter): Response
    {
        return Inertia::render('bcm/Plans', $presenter->index($request, BcmRouteScope::project($request, false)));
    }

    public function show(Request $request, ContinuityPlanPresenter $presenter): Response
    {
        $project = BcmRouteScope::project($request, false);

        return Inertia::render('bcm/Plans', $presenter->show($request, $project, BcmRouteScope::child($request, $project, 'revision', ContinuityPlanRevision::class)));
    }

    public function store(Request $request, ContinuityPlanWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = $workflow->create($project, BcmRouteScope::payload($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.plans.show', [$project->id, $revision->id]);
    }

    public function update(Request $request, ContinuityPlanWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = $workflow->save(BcmRouteScope::child($request, $project, 'revision', ContinuityPlanRevision::class), BcmRouteScope::payload($request), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.plans.show', [$project->id, $revision->id]);
    }

    public function approve(Request $request, ContinuityPlanWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = $workflow->approve(BcmRouteScope::child($request, $project, 'revision', ContinuityPlanRevision::class), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.plans.show', [$project->id, $revision->id]);
    }

    public function revise(Request $request, ContinuityPlanWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = $workflow->revise(BcmRouteScope::child($request, $project, 'revision', ContinuityPlanRevision::class), BcmRouteScope::actor($request));

        return redirect()->route('bcm.plans.show', [$project->id, $revision->id]);
    }
}
