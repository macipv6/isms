<?php

namespace App\Http\Controllers;

use App\Enums\ThreatReviewStatus;
use App\Http\Requests\Risks\ReviewThreatRequest;
use App\Http\RiskRouteScope;
use App\Models\RiskTargetThreatReview;
use App\Services\Threats\ThreatOverviewService;
use Illuminate\Http\RedirectResponse;

class ThreatReviewController extends RiskController
{
    public function update(ReviewThreatRequest $request, ThreatOverviewService $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $review = RiskRouteScope::child($request, $project, 'review', RiskTargetThreatReview::class);
        $data = $request->validated();
        $service->review($review, ThreatReviewStatus::from($data['status']), $data['rationale'] ?? null, $this->actor($request));

        return back()->with('success', 'Gefährdung bewertet.');
    }
}
