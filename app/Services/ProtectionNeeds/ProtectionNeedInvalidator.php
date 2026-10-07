<?php

namespace App\Services\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Risks\RiskTriggerEvaluator;

class ProtectionNeedInvalidator
{
    public function __construct(private readonly AuditLogger $audit, private readonly RiskTriggerEvaluator $triggers) {}

    public function refreshRiskTarget(DependencyNode $target, bool $recordAudit = true, ?User $actor = null): void
    {
        $this->triggers->refreshTarget(IsmsProject::query()->findOrFail($target->projectId), $target, $recordAudit, $actor);
    }

    public function invalidate(ProtectionNeed $need, ?User $actor = null, bool $recordAudit = true): bool
    {
        $requestCount = $need->downgradeRequests()->whereIn('status', ['pending', 'approved'])->update(['status' => 'invalidated']);
        $priorAssessment = $need->status->value !== 'draft' || $need->direct_level !== null || $need->inherited_level !== null || $need->effective_level !== null;
        $reviewChanged = ! $need->needs_review && $priorAssessment;
        if (! $reviewChanged && $requestCount === 0) {
            return false;
        }
        $need->update(['needs_review' => true, 'lock_version' => $need->lock_version + 1]);
        if ($recordAudit) {
            $this->audit->record('protection_need.invalidated', $actor, [
                'project_id' => $need->project_id,
                'protection_need_id' => $need->id,
                'objective' => $need->objective->value,
                'new_status' => 'needs_review',
                'changed_fields' => array_values(array_filter([
                    $reviewChanged ? 'needs_review' : null,
                    $requestCount > 0 ? 'downgrade_requests' : null,
                ])),
            ], $need->project->organization_id);
        }

        return true;
    }

    public function invalidateTarget(DependencyNode $target, ?User $actor = null): void
    {
        $column = $target->type === DependencyNodeType::Process ? 'business_process_id' : 'asset_id';
        foreach (ProtectionNeed::query()->where('project_id', $target->projectId)->where($column, $target->id)->get() as $need) {
            $this->invalidate($need, $actor);
        }
    }
}
