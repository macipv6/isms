<?php

namespace App\Services\Bcm;

use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\BiaRevision;
use App\Models\ContinuityPlanRevision;
use App\Models\IsmsProject;
use Illuminate\Http\Request;

class ContinuityPlanPresenter
{
    public function __construct(private readonly BcmWorkspacePresenter $workspace, private readonly BcmFreshness $freshness) {}

    /** @return array<string, mixed> */
    public function index(Request $request, IsmsProject $project): array
    {
        $props = $this->workspace->base($request, $project);
        $props['revision'] = null;
        $props['entries'] = $this->workspace->paginate($request, ContinuityPlanRevision::query()->where('project_id', $project->id)->orderByDesc('created_at')->orderBy('id'), $this->summary(...));

        return $props;
    }

    /** @return array<string, mixed> */
    public function show(Request $request, IsmsProject $project, ContinuityPlanRevision $revision): array
    {
        $props = $this->workspace->base($request, $project);
        $props['revision'] = [...$this->summary($revision), ...$revision->only(['scenario', 'activation_conditions', 'strategy', 'minimum_operation', 'normal_operation_return', 'approved_at']),
            'steps' => $revision->steps()->orderBy('position')->limit(200)->get()->map(fn ($row): array => $row->only(['id', 'position', 'instruction', 'owner_name', 'expected_minutes']))->all(),
            'roles' => $revision->roles()->orderBy('position')->limit(200)->get()->map(fn ($row): array => $row->only(['id', 'position', 'role', 'name', 'email', 'phone', 'deputy_name']))->all(),
            'process_ids' => $revision->processes()->orderBy('business_process_id')->limit(200)->pluck('business_process_id')->all(),
            'evidence_ids' => $revision->evidenceLinks()->orderBy('evidence_id')->limit(200)->pluck('evidence_id')->all(),
            'risk_analysis_ids' => $revision->riskLinks()->orderBy('risk_analysis_id')->limit(200)->pluck('risk_analysis_id')->all(),
            'lists_complete' => $revision->steps()->count() <= 200 && $revision->roles()->count() <= 200 && $revision->processes()->count() <= 200 && $revision->evidenceLinks()->count() <= 200 && $revision->riskLinks()->count() <= 200,
        ];
        $props['capabilities'] = $this->workspace->capabilities($props['capabilities']['write'], $revision->status->value);
        $props['capabilities']['edit'] = $props['capabilities']['edit'] && $props['revision']['lists_complete'];
        $props['history'] = $this->workspace->history($request, ContinuityPlanRevision::query()->where('project_id', $project->id)->where('continuity_plan_id', $revision->continuity_plan_id));
        $props['lock_version'] = $revision->lock_version;
        $props['sources'] = $this->workspace->items($request, $this->sourceRows($revision), 'sources_page');

        return $props;
    }

    /** Display server-pinned references only after rechecking project ownership.
     * @return list<array<string, mixed>>
     */
    public function sourceRows(ContinuityPlanRevision $revision): array
    {
        $rows = [];
        if ($revision->status->value === 'draft') {
            foreach ($revision->processes()->orderBy('business_process_id')->get() as $link) {
                $bia = BiaRevision::query()->where('project_id', $revision->project_id)->whereHas('analysis', fn ($q) => $q->where('business_process_id', $link->business_process_id))->where('status', 'approved')->orderByDesc('version')->first();
                $rows[] = ['target_type' => 'process', 'target_id' => $link->business_process_id, 'revision_id' => $bia?->id, 'version' => $bia?->version, 'freshness' => $bia ? $this->freshness->bia($bia) : ['current' => false, 'reasons' => ['source_missing']]];
            }
        } else {
            foreach (['processes' => ['process', BiaRevision::class, 'process_id'], 'assets' => ['asset', AssetRecoveryObjectiveRevision::class, 'asset_id']] as $key => [$type, $class, $targetKey]) {
                foreach ($revision->source_snapshot[$key] ?? [] as $source) {
                    $model = $class::query()->where('project_id', $revision->project_id)->find($source['revision_id'] ?? null);
                    if (! $model) {
                        continue;
                    }
                    $targetId = $model instanceof BiaRevision ? $model->analysis->business_process_id : $model->asset_id;
                    if ($targetId !== ($source[$targetKey] ?? null)) {
                        continue;
                    }
                    $rows[] = ['target_type' => $type, 'target_id' => $targetId, 'revision_id' => $model->id, 'version' => $model->version];
                }
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function summary(ContinuityPlanRevision $revision): array
    {
        return [...$revision->only(['id', 'continuity_plan_id', 'version', 'lock_version', 'status', 'title']), 'freshness' => $this->freshness->plan($revision)];
    }
}
