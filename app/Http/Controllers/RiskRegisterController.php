<?php

namespace App\Http\Controllers;

use App\Services\Risks\RiskRegisterPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RiskRegisterController extends RiskController
{
    public function __invoke(Request $request, RiskRegisterPresenter $presenter): Response
    {
        [, $project] = $this->scopedProject($request, false);
        $props = $presenter->index($request, $project);
        $query = http_build_query($props['filters']);
        Inertia::resolveUrlUsing(fn (Request $request): string => $request->getPathInfo().($query !== '' ? '?'.$query : ''));

        return Inertia::render('risks/Index', $props);
    }
}
