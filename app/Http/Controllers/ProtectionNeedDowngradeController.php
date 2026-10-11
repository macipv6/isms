<?php

namespace App\Http\Controllers;

use App\Enums\ProtectionLevel;
use App\Http\Requests\Risks\DecideDowngradeRequest;
use App\Http\RiskRouteScope;
use App\Models\EvidenceFile;
use App\Models\ProtectionNeed;
use App\Models\ProtectionNeedDowngradeRequest;
use App\Services\ProtectionNeeds\DowngradeWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProtectionNeedDowngradeController extends RiskController
{
    public function store(DecideDowngradeRequest $request, DowngradeWorkflow $workflow): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $need = RiskRouteScope::child($request, $project, 'need', ProtectionNeed::class);
        Gate::authorize('requestDowngrade', $need);
        $data = $request->validated();
        $workflow->request($need, ProtectionLevel::from($data['requested_level']), $data['rationale'], $this->actor($request));

        return back()->with('success', 'Herabstufung beantragt.');
    }

    public function decide(DecideDowngradeRequest $request, DowngradeWorkflow $workflow): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $downgrade = RiskRouteScope::child($request, $project, 'downgrade', ProtectionNeedDowngradeRequest::class);
        $data = $request->validated();
        if ($data['action'] === 'external') {
            Gate::authorize('recordExternalDowngrade', [ProtectionNeed::class, $downgrade]);
            $evidence = isset($data['evidence_id']) ? EvidenceFile::query()->where('project_id', $project->id)->findOrFail($data['evidence_id']) : null;
            $workflow->recordExternal($downgrade, $data['approved'], $data['name'], $data['function'], $data['decision_date'], $data['rationale'], $evidence, $this->actor($request));
        } else {
            Gate::authorize('decideDowngrade', [ProtectionNeed::class, $downgrade]);
            $workflow->decideInternal($downgrade, $data['approved'], $data['rationale'], $this->actor($request));
        }

        return back()->with('success', 'Herabstufung entschieden.');
    }
}
