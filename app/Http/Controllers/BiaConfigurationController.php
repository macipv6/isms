<?php

namespace App\Http\Controllers;

use App\Http\BcmRouteScope;
use App\Services\Bcm\BiaConfigurationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BiaConfigurationController extends Controller
{
    public function initialize(Request $request, BiaConfigurationWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $workflow->initialize($project, BcmRouteScope::actor($request));

        return redirect()->route('bcm.index', $project->id);
    }

    public function activate(Request $request, BiaConfigurationWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $data = BcmRouteScope::payload($request);
        if (! isset($data['minutes']) || ! is_array($data['minutes'])) {
            throw ValidationException::withMessages(['minutes' => 'Bitte geben Sie eine Liste von Minutenwerten an.']);
        }
        $workflow->activate($project, $data['minutes'], BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.index', $project->id);
    }
}
