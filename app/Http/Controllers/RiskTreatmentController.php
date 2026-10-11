<?php

namespace App\Http\Controllers;

use App\Enums\RiskTreatmentAction;
use App\Http\Requests\Risks\StoreRiskTreatmentRequest;
use App\Http\RiskRouteScope;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\Measure;
use App\Models\RiskAnalysisCycle;
use App\Services\Risks\RiskTreatmentWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RiskTreatmentController extends RiskController
{
    public function select(StoreRiskTreatmentRequest $request, RiskTreatmentWorkflow $service): RedirectResponse
    {
        [, $project] = $this->scopedProject($request);
        $cycle = RiskRouteScope::child($request, $project, 'cycle', RiskAnalysisCycle::class);
        Gate::authorize('update', $cycle);
        $data = $request->validated();
        $service->select($cycle, RiskTreatmentAction::from($data['action']), $data, $this->actor($request));

        return back()->with('success', 'Risikobehandlung gespeichert.');
    }

    public function link(Request $request, RiskTreatmentWorkflow $service): RedirectResponse
    {
        [$cycle, $resource, $kind] = $this->scopedLink($request);
        $method = 'link'.ucfirst($kind);
        $service->{$method}($cycle, $resource, $this->actor($request));

        return back()->with('success', 'Verknüpfung gespeichert.');
    }

    public function unlink(Request $request, RiskTreatmentWorkflow $service): RedirectResponse
    {
        [$cycle, $resource, $kind] = $this->scopedLink($request);
        $method = 'unlink'.ucfirst($kind);
        $service->{$method}($cycle, $resource, $this->actor($request));

        return back()->with('success', 'Verknüpfung entfernt.');
    }

    /** @return array{RiskAnalysisCycle, Finding|Measure|EvidenceFile, string} */
    private function scopedLink(Request $request): array
    {
        [, $project] = $this->scopedProject($request);
        $cycle = RiskRouteScope::child($request, $project, 'cycle', RiskAnalysisCycle::class);
        Gate::authorize('update', $cycle);
        $kind = $request->route('kind');
        $class = match ($kind) {
            'finding' => Finding::class,
            'measure' => Measure::class,
            'evidence' => EvidenceFile::class,
            default => abort(404),
        };
        $resource = RiskRouteScope::child($request, $project, 'resource', $class);

        return [$cycle, $resource, $kind];
    }
}
