<?php

namespace App\Services\Bcm;

use App\Enums\BcmRevisionStatus;
use App\Models\Asset;
use App\Models\BiaRevision;
use App\Models\BusinessProcess;
use App\Models\ContinuityPlanRevision;
use App\Models\EvidenceFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BcmFreshness
{
    public function __construct(private readonly BcmSourceSnapshot $sources) {}

    /** @return array{current: bool, reasons: list<string>} */
    public function bia(BiaRevision $revision): array
    {
        $resolved = [];

        return $this->check($revision, [], $resolved);
    }

    /** @return array{current: bool, reasons: list<string>} */
    public function plan(ContinuityPlanRevision $revision): array
    {
        $revision = ContinuityPlanRevision::query()->whereKey($revision->id)->where('project_id', $revision->project_id)->first();
        if (! $revision) {
            return ['current' => false, 'reasons' => ['source_missing']];
        }
        if ($revision->status !== BcmRevisionStatus::Approved) {
            return ['current' => false, 'reasons' => ['not_approved']];
        }
        $reasons = [];
        if (ContinuityPlanRevision::query()->where('project_id', $revision->project_id)->where('continuity_plan_id', $revision->continuity_plan_id)->where('status', 'approved')->where('version', '>', $revision->version)->exists()) {
            $reasons[] = 'newer_revision';
        }
        $pinned = $revision->source_snapshot;
        if (! isset($pinned['processes'], $pinned['assets'], $pinned['evidence_ids'], $pinned['risk_analysis_ids']) || $pinned['processes'] === []) {
            return ['current' => false, 'reasons' => [...$reasons, 'source_snapshot_missing']];
        }
        try {
            $current = $this->sources->plan($revision);
        } catch (ModelNotFoundException|NotFoundHttpException) {
            return ['current' => false, 'reasons' => [...$reasons, 'source_missing']];
        }
        foreach (['processes' => 'bia_sources_changed', 'assets' => 'asset_sources_changed', 'evidence_ids' => 'evidence_sources_changed', 'risk_analysis_ids' => 'risk_sources_changed'] as $key => $reason) {
            if ($pinned[$key] != $current[$key]) {
                $reasons[] = $reason;
            }
        }
        foreach ($pinned['processes'] as $process) {
            $target = BusinessProcess::query()->whereKey($process['process_id'])->where('project_id', $revision->project_id)->first();
            if (! $target || ! $target->is_active) {
                $reasons[] = $target ? 'process_inactive' : 'source_missing';
            }
            $bia = BiaRevision::query()->whereKey($process['revision_id'])->where('project_id', $revision->project_id)->first();
            $state = $bia ? $this->bia($bia) : ['current' => false, 'reasons' => ['source_missing']];
            if (! $state['current']) {
                $reasons[] = 'bia_review_required';
                if (in_array('freshness_cycle', $state['reasons'], true)) {
                    $reasons[] = 'freshness_cycle';
                }
            }
        }
        foreach ($pinned['assets'] as $asset) {
            if (! Asset::query()->whereKey($asset['asset_id'])->where('project_id', $revision->project_id)->where('is_active', true)->exists()) {
                $reasons[] = 'asset_inactive';
            }
        }
        foreach ($pinned['evidence_ids'] as $id) {
            $proof = EvidenceFile::query()->whereKey($id)->where('project_id', $revision->project_id)->first();
            if (! $proof || $proof->status->value !== 'verified' || $proof->reviewed_by === null || $proof->reviewed_at === null) {
                $reasons[] = 'evidence_unverified';
            }
        }
        $reasons = array_values(array_unique($reasons));

        return ['current' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * @param  list<string>  $visiting
     * @param  array<string, array{current: bool, reasons: list<string>}>  $resolved
     * @return array{current: bool, reasons: list<string>}
     */
    private function check(BiaRevision $revision, array $visiting, array &$resolved): array
    {
        if (in_array($revision->id, $visiting, true)) {
            return ['current' => false, 'reasons' => ['freshness_cycle']];
        }
        if (isset($resolved[$revision->id])) {
            return $resolved[$revision->id];
        }
        $revision = BiaRevision::query()->whereKey($revision->id)->where('project_id', $revision->project_id)->first();
        if (! $revision) {
            return ['current' => false, 'reasons' => ['source_missing']];
        }
        if ($revision->status !== BcmRevisionStatus::Approved) {
            return ['current' => false, 'reasons' => ['not_approved']];
        }
        $process = BusinessProcess::query()->whereKey($revision->analysis->business_process_id)->where('project_id', $revision->project_id)->first();
        if (! $process || ! $process->is_active) {
            return ['current' => false, 'reasons' => [$process ? 'root_inactive' : 'source_missing']];
        }
        $reasons = [];
        if (BiaRevision::query()->where('business_impact_analysis_id', $revision->business_impact_analysis_id)->where('project_id', $revision->project_id)->where('status', 'approved')->where('version', '>', $revision->version)->exists()) {
            $reasons[] = 'newer_revision';
        }
        $pinned = $revision->source_snapshot['sources'] ?? null;
        if (! is_array($pinned)) {
            return ['current' => false, 'reasons' => [...$reasons, 'source_snapshot_missing']];
        }
        try {
            $current = $this->sources->bia($revision);
        } catch (ModelNotFoundException|NotFoundHttpException) {
            return ['current' => false, 'reasons' => [...$reasons, 'source_missing']];
        }
        foreach (['configuration' => 'configuration_changed', 'dependencies' => 'dependency_sources_changed', 'edges' => 'dependency_graph_changed', 'root_targets' => 'root_targets_changed', 'impacts' => 'impacts_changed'] as $key => $reason) {
            // PostgreSQL jsonb reorders object keys; list order and values still matter.
            if (($pinned[$key] ?? null) != $current[$key]) {
                $reasons[] = $reason;
            }
        }
        if ($current['configuration']['pinned_id'] !== $current['configuration']['current_id'] && ! in_array('configuration_changed', $reasons, true)) {
            $reasons[] = 'configuration_changed';
        }
        $visiting[] = $revision->id;
        foreach ($current['dependencies'] as $dependency) {
            if ($dependency['target_type'] !== 'process' || $dependency['objective'] === null) {
                continue;
            }
            $target = BiaRevision::query()->whereKey($dependency['objective']['id'])->where('project_id', $revision->project_id)->first();
            $targetState = $target ? $this->check($target, $visiting, $resolved) : ['current' => false, 'reasons' => ['source_missing']];
            if (! $targetState['current']) {
                $reasons[] = 'dependency_review_required';
                if (in_array('freshness_cycle', $targetState['reasons'], true)) {
                    $reasons[] = 'freshness_cycle';
                }
            }
        }
        $reasons = array_values(array_unique($reasons));

        return $resolved[$revision->id] = ['current' => $reasons === [], 'reasons' => $reasons];
    }
}
