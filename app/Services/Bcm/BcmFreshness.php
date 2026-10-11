<?php

namespace App\Services\Bcm;

use App\Enums\BcmRevisionStatus;
use App\Models\BiaRevision;
use App\Models\BusinessProcess;
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
