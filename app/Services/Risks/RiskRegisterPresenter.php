<?php

namespace App\Services\Risks;

use App\Models\IsmsProject;
use App\Models\ProjectRiskContext;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskAnalysisTrigger;
use App\Policies\ProjectRiskPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiskRegisterPresenter
{
    /** @return array<string, mixed> */
    public function index(Request $request, IsmsProject $project): array
    {
        $filters = validator($request->query(), [
            'target_type' => ['nullable', Rule::in(['process', 'asset'])],
            'status' => ['nullable', 'string', 'max:40'],
            'stale' => ['nullable', Rule::in(['yes', 'no'])],
            'threat' => ['nullable', 'uuid'],
        ])->validate();
        $context = ProjectRiskContext::query()->where('project_id', $project->id)->first();
        $triggers = RiskAnalysisTrigger::query()->where('project_id', $project->id)
            ->when(($filters['target_type'] ?? null) === 'process', fn ($q) => $q->whereNotNull('business_process_id'))
            ->when(($filters['target_type'] ?? null) === 'asset', fn ($q) => $q->whereNotNull('asset_id'))
            ->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (RiskAnalysisTrigger $trigger): array => [
                'id' => $trigger->id, 'targetType' => $trigger->business_process_id ? 'process' : 'asset',
                'targetId' => $trigger->business_process_id ?? $trigger->asset_id,
                'type' => $trigger->type->value, 'status' => $trigger->status->value,
                'rationale' => mb_substr((string) $trigger->rationale, 0, 10000),
            ])->all();
        $query = RiskAnalysisCycle::query()->where('risk_analysis_cycles.project_id', $project->id)
            ->join('risk_analyses as analyses', function ($join): void {
                $join->on('analyses.id', '=', 'risk_analysis_cycles.risk_analysis_id')->on('analyses.project_id', '=', 'risk_analysis_cycles.project_id');
            })
            ->select(['risk_analysis_cycles.*', 'analyses.business_process_id as target_process_id', 'analyses.asset_id as target_asset_id', 'analyses.project_threat_id'])
            ->with(['treatment', 'currentResidualAssessment.riskLevel', 'decisions' => fn ($q) => $q->latest()->limit(5)]);
        if (($filters['target_type'] ?? null) === 'process') {
            $query->whereNotNull('analyses.business_process_id');
        } elseif (($filters['target_type'] ?? null) === 'asset') {
            $query->whereNotNull('analyses.asset_id');
        }
        if (isset($filters['status'])) {
            $query->where('risk_analysis_cycles.status', $filters['status']);
        }
        if (isset($filters['stale'])) {
            $query->where('risk_analysis_cycles.needs_review', $filters['stale'] === 'yes');
        }
        if (isset($filters['threat'])) {
            $query->where('analyses.project_threat_id', $filters['threat']);
        }
        $page = $query->orderByDesc('risk_analysis_cycles.created_at')->paginate(25)->appends($filters);
        $cycles = [];
        foreach ($page->items() as $cycle) {
            $decisions = [];
            foreach ($cycle->decisions as $decision) {
                $decisions[] = [
                    'id' => $decision->id, 'status' => $decision->getRawOriginal('status'),
                    'channel' => $decision->getRawOriginal('channel'), 'date' => (string) $decision->decision_date,
                ];
            }
            $processId = $cycle->getAttribute('target_process_id');
            $assetId = $cycle->getAttribute('target_asset_id');
            $cycles[] = [
                'id' => $cycle->id, 'analysisId' => $cycle->risk_analysis_id,
                'targetType' => $processId ? 'process' : 'asset',
                'targetId' => $processId ?? $assetId,
                'threatId' => $cycle->getAttribute('project_threat_id'),
                'title' => $cycle->title, 'cause' => mb_substr((string) $cycle->cause, 0, 10000),
                'event' => mb_substr((string) $cycle->event, 0, 10000), 'impact' => mb_substr((string) $cycle->impact, 0, 10000),
                'status' => $cycle->status->value, 'current' => $cycle->is_current,
                'needsReview' => $cycle->needs_review, 'lockVersion' => $cycle->lock_version,
                'treatment' => $cycle->treatment ? ['id' => $cycle->treatment->id, 'action' => $cycle->treatment->action->value] : null,
                'residualLevel' => $cycle->currentResidualAssessment?->riskLevel?->key,
                'decisions' => $decisions,
            ];
        }

        return [
            'project' => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status->value],
            'context' => $context ? ['id' => $context->id, 'methodologyVersionId' => $context->methodology_version_id, 'catalogVersionId' => $context->catalog_version_id] : null,
            'filters' => $filters,
            'capabilities' => ['write' => app(ProjectRiskPolicy::class)->write($request->user(), $project)],
            'triggers' => $triggers,
            'cycles' => ['data' => $cycles, 'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => 25], 'links' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()]],
        ];
    }
}
