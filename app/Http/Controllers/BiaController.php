<?php

namespace App\Http\Controllers;

use App\Http\BcmRouteScope;
use App\Models\BiaRevision;
use App\Models\BusinessProcess;
use App\Services\Bcm\BiaPresenter;
use App\Services\Bcm\BiaWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BiaController extends Controller
{
    public function index(Request $request, BiaPresenter $presenter): Response
    {
        return Inertia::render('bcm/Bia', $presenter->index($request, BcmRouteScope::project($request, false)));
    }

    public function show(Request $request, BiaPresenter $presenter): Response
    {
        $project = BcmRouteScope::project($request, false);

        return Inertia::render('bcm/Bia', $presenter->show($request, $project, BcmRouteScope::child($request, $project, 'revision', BiaRevision::class)));
    }

    public function store(Request $request, BiaWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = $workflow->draft(BcmRouteScope::inputChild($request, $project, 'business_process_id', BusinessProcess::class), BcmRouteScope::actor($request));

        return redirect()->route('bcm.bia.show', [$project->id, $revision->id]);
    }

    public function update(Request $request, BiaWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = $workflow->save(BcmRouteScope::child($request, $project, 'revision', BiaRevision::class), BcmRouteScope::payload($request), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.bia.show', [$project->id, $revision->id]);
    }

    public function approve(Request $request, BiaWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = $workflow->approve(BcmRouteScope::child($request, $project, 'revision', BiaRevision::class), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.bia.show', [$project->id, $revision->id]);
    }

    public function revise(Request $request, BiaWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $original = BcmRouteScope::child($request, $project, 'revision', BiaRevision::class);
        $revision = $workflow->draft($original->analysis->process, BcmRouteScope::actor($request));

        return redirect()->route('bcm.bia.show', [$project->id, $revision->id]);
    }
}
