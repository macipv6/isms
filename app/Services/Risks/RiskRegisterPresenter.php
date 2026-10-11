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
            'status' => ['nullable', Rule::in(array_column(\App\Enums\RiskCycleStatus::cases(), 'value'))],
            'stale' => ['nullable', Rule::in(['yes', 'no'])],
            'threat' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::in(['low', 'medium', 'high', 'very_high'])],
            'decision_required' => ['nullable', Rule::in(['yes', 'no'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'target_page' => ['nullable', 'integer', 'min:1'],
            'reference_page' => ['nullable', 'integer', 'min:1'],
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
            ->with(['treatment', 'assessments.riskLevel', 'currentResidualAssessment.riskLevel', 'decisions' => fn ($q) => $q->latest()->limit(100), 'findingLinks', 'measureLinks', 'evidenceLinks']);
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
        if (isset($filters['category'])) {
            $query->whereHas('currentResidualAssessment.riskLevel', fn ($q) => $q->where('key', $filters['category']));
        }
        if (isset($filters['decision_required'])) {
            $query->where('risk_analysis_cycles.status', $filters['decision_required'] === 'yes' ? '=' : '<>', 'awaiting_decision');
        }
        $page = $query->orderByDesc('risk_analysis_cycles.created_at')->paginate(25)->appends($filters);
        $cycles = [];
        foreach ($page->items() as $cycle) {
            $decisions = [];
            foreach ($cycle->decisions as $decision) {
                $decisions[] = [
                    'id' => $decision->id, 'status' => $decision->getRawOriginal('status'),
                    'channel' => $decision->getRawOriginal('channel'), 'date' => (string) $decision->decision_date,
                    'rationale' => $decision->rationale, 'invalidated' => $decision->invalidated_at !== null,
                    'name' => $decision->external_decision_maker_name, 'function' => $decision->external_decision_maker_function,
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
                'treatment' => $cycle->treatment ? [
                    'id' => $cycle->treatment->id, 'action' => $cycle->treatment->action->value,
                    'rationale' => mb_substr((string) $cycle->treatment->rationale, 0, 10000),
                    'counterparty_name' => mb_substr((string) $cycle->treatment->counterparty_name, 0, 255),
                    'counterparty_email' => mb_substr((string) $cycle->treatment->counterparty_email, 0, 255),
                    'cessation_statement' => mb_substr((string) $cycle->treatment->cessation_statement, 0, 10000),
                    'cessation_evidence_id' => $cycle->treatment->cessation_evidence_id,
                ] : null,
                'residualLevel' => $cycle->currentResidualAssessment?->riskLevel?->key,
                'decisions' => $decisions,
                'cycleNumber' => $cycle->cycle_number,
                'assessments' => $cycle->assessments->map(fn ($assessment) => ['id' => $assessment->id, 'phase' => $assessment->phase->value, 'revision' => $assessment->revision, 'category' => $assessment->riskLevel->key, 'rationale' => $assessment->rationale, 'methodologyVersionId' => $assessment->methodology_version_id])->all(),
                'links' => ['finding' => $cycle->findingLinks->pluck('finding_id')->all(), 'measure' => $cycle->measureLinks->pluck('measure_id')->all(), 'evidence' => $cycle->evidenceLinks->pluck('evidence_id')->all()],
                'canDecideInternal' => $request->user()->can('decideInternal', $cycle),
                'canDecideExternal' => $request->user()->can('decideExternal', $cycle),
                'decisionRequired' => $cycle->status->value === 'awaiting_decision',
                'completionBlockers' => $this->blockers($cycle),
            ];
        }

        return [
            'organization' => ['id' => $project->organization_id],
            'project' => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status->value],
            'context' => $context ? ['id' => $context->id, 'methodologyVersionId' => $context->methodology_version_id, 'catalogVersionId' => $context->catalog_version_id] : null,
            'filters' => $filters,
            'capabilities' => ['write' => app(ProjectRiskPolicy::class)->write($request->user(), $project)],
            'triggers' => $triggers,
            ...$this->workspace($project, $context, $filters),
            'cycles' => ['data' => $cycles, 'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => 25], 'links' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()]],
        ];
    }

    private function blockers(RiskAnalysisCycle $cycle): array
    {
        $blockers = [];
        if ($cycle->needs_review) {
            $blockers[] = 'Der Zyklus muss erneut geprüft werden.';
        }
        $treatment = $cycle->treatment;
        if (! $treatment) {
            $blockers[] = 'Eine Behandlung fehlt.';
        } elseif ($treatment->action->value === 'reduce') {
            $ids = $cycle->measureLinks->pluck('measure_id');
            if ($ids->isEmpty() || \App\Models\Measure::query()->where('project_id', $cycle->project_id)->whereIn('id', $ids)->where('status', 'completed')->whereNotNull('completed_at')->whereNotNull('completed_by')->count() !== $ids->count()) {
                $blockers[] = 'Alle verknüpften Maßnahmen müssen wirksam abgeschlossen sein.';
            }
        } elseif ($treatment->action->value === 'transfer') {
            if (trim((string) $treatment->counterparty_name) === '' || ! \App\Models\EvidenceFile::query()->where('project_id', $cycle->project_id)->whereIn('id', $cycle->evidenceLinks->pluck('evidence_id'))->where('status', 'verified')->exists()) {
                $blockers[] = 'Übertragung benötigt einen Vertragspartner und geprüften Nachweis.';
            }
        } elseif ($treatment->action->value === 'avoid') {
            if (trim((string) $treatment->cessation_statement) === '' || ! \App\Models\EvidenceFile::query()->where('project_id', $cycle->project_id)->whereKey($treatment->cessation_evidence_id)->where('status', 'verified')->whereNotNull('reviewed_by')->whereNotNull('reviewed_at')->exists()) {
                $blockers[] = 'Ein aktueller geprüfter Stilllegungsnachweis fehlt.';
            }
        }
        $residual = $cycle->currentResidualAssessment;
        if (! $residual) {
            $blockers[] = 'Eine Restbewertung fehlt.';
        } elseif (in_array($residual->riskLevel->key, ['high', 'very_high'], true)) {
            if (! $cycle->decisions->contains(fn ($decision) => $decision->status->value === 'accepted' && $decision->invalidated_at === null && $decision->residual_assessment_id === $residual->id)) {
                $blockers[] = 'Eine aktuelle Managementakzeptanz zur Restbewertung fehlt.';
            }
        }

        return $blockers;
    }

    private function workspace(IsmsProject $project, ?ProjectRiskContext $context, array $filters): array
    {
        $versions = app(RiskVersionPresenter::class);
        $methodologies = \App\Models\RiskMethodologyVersion::query()->where('status', 'published')->orderByDesc('published_at')->limit(100)->get();
        $catalogs = \App\Models\ThreatCatalogVersion::query()->where('status', 'published')->orderByDesc('published_at')->limit(100)->get();
        if ($context && ! $methodologies->contains('id', $context->methodology_version_id)) {
            $methodologies->push(\App\Models\RiskMethodologyVersion::query()->findOrFail($context->methodology_version_id));
        }
        if ($context && ! $catalogs->contains('id', $context->catalog_version_id)) {
            $catalogs->push(\App\Models\ThreatCatalogVersion::query()->findOrFail($context->catalog_version_id));
        }
        $targetPage = (int) ($filters['target_page'] ?? 1);
        $referencePage = (int) ($filters['reference_page'] ?? 1);
        $targetLastPage = 1;
        $referenceLastPage = 1;
        $targets = [];
        foreach (['process' => \App\Models\BusinessProcess::class, 'asset' => \App\Models\Asset::class] as $type => $class) {
            $targetQuery = $class::query()->where('project_id', $project->id);
            $targetLastPage = max($targetLastPage, (int) ceil($targetQuery->count() / 100));
            foreach ($targetQuery->orderBy('key')->offset(($targetPage - 1) * 100)->limit(100)->get() as $target) {
                $column = $type === 'process' ? 'business_process_id' : 'asset_id';
                $targets[] = ['id' => $target->id, 'type' => $type, 'key' => $target->key, 'name' => $target->name, 'active' => $target->is_active,
                    'protectionNeeds' => \App\Models\ProtectionNeed::query()->where('project_id', $project->id)->where($column, $target->id)->with(['sources' => fn ($q) => $q->limit(20)])->get()->map(fn ($need) => ['objective' => $need->objective->value, 'level' => $need->effective_level?->value, 'needsReview' => $need->needs_review, 'sources' => $need->sources->map(fn ($source) => $this->source($project, $source, $target, $type))->all()])->all(),
                    'reviews' => \App\Models\RiskTargetThreatReview::query()->where('project_id', $project->id)->where($column, $target->id)->get()->map(fn ($review) => ['id' => $review->id, 'threatId' => $review->project_threat_id, 'status' => $review->status->value, 'rationale' => $review->rationale])->all(),
                ];
            }
        }
        $resources = [];
        foreach (['finding' => \App\Models\Finding::class, 'measure' => \App\Models\Measure::class, 'evidence' => \App\Models\EvidenceFile::class] as $kind => $class) {
            $resourceQuery = $class::query()->where('project_id', $project->id);
            $referenceLastPage = max($referenceLastPage, (int) ceil($resourceQuery->count() / 100));
            $resources[$kind] = $resourceQuery->orderByDesc('created_at')->orderBy('id')->offset(($referencePage - 1) * 100)->limit(100)->get()->map(fn ($resource) => ['id' => $resource->id, 'label' => $resource->title ?? $resource->original_name ?? $resource->id, 'status' => $resource->getRawOriginal('status')])->all();
        }

        return [
            'methodologies' => $methodologies->map(fn ($v) => $versions->methodology($v))->all(),
            'catalogs' => $catalogs->map(fn ($v) => $versions->catalog($v, false))->all(),
            'targets' => $targets, 'resources' => $resources,
            'referencePages' => ['target' => ['current' => $targetPage, 'last' => $targetLastPage], 'resource' => ['current' => $referencePage, 'last' => $referenceLastPage]],
            'threats' => $context ? $context->projectThreats()->currentForCatalog($context->catalog_version_id)->get()->map(fn ($threat) => ['id' => $threat->id, 'code' => $threat->code, 'title' => $threat->title, 'description' => $threat->description])->all() : [],
        ];
    }

    private function source(IsmsProject $project, $source, $target, string $type): array
    {
        $sourceType = $source->origin_process_id ? 'process' : 'asset';
        $class = $sourceType === 'process' ? \App\Models\BusinessProcess::class : \App\Models\Asset::class;
        $origin = $class::query()->where('project_id', $project->id)->find($source->origin_process_id ?? $source->origin_asset_id);
        $edge = \App\Models\DependencyEdge::query()->where('project_id', $project->id)->find($source->first_edge_id);
        $nextType = $edge?->target_process_id ? 'process' : 'asset';
        $nextClass = $nextType === 'process' ? \App\Models\BusinessProcess::class : \App\Models\Asset::class;
        $next = $edge ? $nextClass::query()->where('project_id', $project->id)->find($edge->target_process_id ?? $edge->target_asset_id) : null;
        $path = [$origin?->key ?? 'Unbekannte Quelle'];
        if ($next && ($next->id !== $origin?->id || $nextType !== $sourceType)) {
            $path[] = $next->key;
        }
        if (! $next || $next->id !== $target->id || $nextType !== $type) {
            $path[] = '…';
            $path[] = $target->key;
        }

        return ['source_type' => $sourceType, 'source_id' => $origin?->id ?? '', 'path' => $path];
    }
}
