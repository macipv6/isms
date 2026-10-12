<?php

namespace App\Http\Controllers;

use App\Http\BcmRouteScope;
use App\Models\Asset;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Services\Bcm\AssetRecoveryWorkflow;
use App\Services\Bcm\BcmWorkspacePresenter;
use App\Services\Bcm\BiaPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class AssetRecoveryController extends Controller
{
    public function show(Request $request, BiaPresenter $presenter): Response
    {
        $project = BcmRouteScope::project($request, false);
        $asset = BcmRouteScope::child($request, $project, 'asset', Asset::class);
        $revision = $request->route('revision') !== null ? BcmRouteScope::child($request, $project, 'revision', AssetRecoveryObjectiveRevision::class) : null;

        return app(BcmWorkspacePresenter::class)->render($request, 'bcm/AssetRecovery', $presenter->asset($request, $project, $asset, $revision));
    }

    public function store(Request $request, AssetRecoveryWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $asset = BcmRouteScope::child($request, $project, 'asset', Asset::class);
        $revision = $workflow->draft($asset, BcmRouteScope::actor($request));

        return redirect()->route('bcm.assets.show', [$project->id, $asset->id, $revision->id]);
    }

    public function update(Request $request, AssetRecoveryWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = $workflow->save(BcmRouteScope::child($request, $project, 'revision', AssetRecoveryObjectiveRevision::class), BcmRouteScope::payload($request), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.assets.show', [$project->id, $revision->asset_id, $revision->id]);
    }

    public function approve(Request $request, AssetRecoveryWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = $workflow->approve(BcmRouteScope::child($request, $project, 'revision', AssetRecoveryObjectiveRevision::class), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.assets.show', [$project->id, $revision->asset_id, $revision->id]);
    }
}
