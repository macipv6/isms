<?php

namespace App\Http\Controllers;

use App\Http\BcmRouteScope;
use App\Services\Bcm\BcmWorkspacePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BcmWorkspaceController extends Controller
{
    public function __invoke(Request $request, BcmWorkspacePresenter $presenter): Response
    {
        $project = BcmRouteScope::project($request, false);

        return Inertia::render('bcm/Index', $presenter->base($request, $project));
    }
}
