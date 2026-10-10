<?php

namespace App\Http\Controllers;

use App\Services\ProtectionNeeds\ProtectionNeedPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProtectionNeedRegisterController extends RiskController
{
    public function __invoke(Request $request, ProtectionNeedPresenter $presenter): Response
    {
        [, $project] = $this->scopedProject($request, false);
        $props = $presenter->index($request, $project);
        $filters = array_filter(['target_type' => $props['filters']['targetType'], 'state' => $props['filters']['state']]);
        $query = http_build_query($filters);
        Inertia::resolveUrlUsing(fn (Request $request): string => $request->getPathInfo().($query !== '' ? '?'.$query : ''));

        return Inertia::render('protection-needs/Index', $props);
    }
}
