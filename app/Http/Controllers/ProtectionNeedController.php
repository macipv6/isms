<?php

namespace App\Http\Controllers;

use App\Http\Requests\Risks\RateProtectionNeedRequest;
use App\Http\Requests\Risks\RiskValidationMessages;
use App\Http\RiskRouteScope;
use App\Services\ProtectionNeeds\ProtectionNeedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProtectionNeedController extends RiskController
{
    public function rate(RateProtectionNeedRequest $request, ProtectionNeedService $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $target = RiskRouteScope::target($request, $project);
        $data = $request->validated();
        $service->rateTarget($project, $target, $data['ratings'], $this->actor($request), $data['expected_versions']);

        return back()->with('success', 'Schutzbedarf gespeichert.');
    }

    public function approve(Request $request, ProtectionNeedService $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $target = RiskRouteScope::target($request, $project);
        $data = validator($request->all(), [
            'expected_versions' => ['required', 'array:confidentiality,integrity,availability'],
            'expected_versions.confidentiality' => ['required', 'integer', 'min:0'],
            'expected_versions.integrity' => ['required', 'integer', 'min:0'],
            'expected_versions.availability' => ['required', 'integer', 'min:0'],
        ], RiskValidationMessages::all())->validate();
        $service->approveTarget($project, $target, $this->actor($request), $data['expected_versions']);

        return back()->with('success', 'Schutzbedarf freigegeben.');
    }
}
