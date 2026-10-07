<?php

namespace App\Services\Threats;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Enums\ThreatReviewStatus;
use App\Models\IsmsProject;
use App\Models\RiskTargetThreatReview;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ProtectionNeeds\ProtectionNeedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ThreatOverviewService
{
    public function __construct(private readonly ProtectionNeedAccess $access, private readonly AuditLogger $audit) {}

    public function ensureTarget(IsmsProject $project, DependencyNode $target): void
    {
        $this->access->target($project, $target);
        $column = $target->type === DependencyNodeType::Process ? 'business_process_id' : 'asset_id';
        $currentCatalog = DB::table('project_risk_contexts')->where('project_id', $project->id)->value('catalog_version_id');
        $ids = DB::table('project_threats')->where('project_id', $project->id)->where('is_active', true)->where(fn ($q) => $q->where('catalog_version_id', $currentCatalog)->orWhereNull('threat_catalog_entry_id'))->pluck('id');
        foreach ($ids as $threatId) {
            RiskTargetThreatReview::query()->firstOrCreate(['project_id' => $project->id, $column => $target->id, 'project_threat_id' => $threatId], ['status' => ThreatReviewStatus::Unreviewed]);
        }
    }

    public function ensureForThreat(IsmsProject $project, string $threatId): void
    {
        foreach (['business_process_id', 'asset_id'] as $column) {
            $ids = DB::table('risk_analysis_triggers')->where('project_id', $project->id)->whereNotNull($column)->whereIn('type', ['elevated_protection_need', 'insufficient_baseline_coverage', 'nonstandard_scenario'])->whereIn('status', ['open', 'covered'])->distinct()->pluck($column);
            foreach ($ids as $id) {
                RiskTargetThreatReview::query()->firstOrCreate(['project_id' => $project->id, $column => $id, 'project_threat_id' => $threatId], ['status' => ThreatReviewStatus::Unreviewed]);
            }
        }
    }

    public function review(RiskTargetThreatReview $review, ThreatReviewStatus $status, ?string $rationale, User $actor): RiskTargetThreatReview
    {
        if ($status === ThreatReviewStatus::Unreviewed) {
            throw ValidationException::withMessages(['status' => 'Wählen Sie anwendbar oder nicht anwendbar.']);
        }
        $rationale = $rationale === null ? null : trim($rationale);
        if ($status === ThreatReviewStatus::NotApplicable && ($rationale === null || $rationale === '' || mb_strlen($rationale) > 10000)) {
            throw ValidationException::withMessages(['rationale' => 'Für nicht anwendbare Gefährdungen ist eine Begründung mit höchstens 10000 Zeichen erforderlich.']);
        }

        return DB::transaction(function () use ($review, $status, $rationale, $actor): RiskTargetThreatReview {
            $actor = $this->access->writer($actor);
            $locked = RiskTargetThreatReview::query()->lockForUpdate()->findOrFail($review->id);
            $project = $this->access->project(IsmsProject::query()->findOrFail($locked->project_id));
            $currentCatalog = DB::table('project_risk_contexts')->where('project_id', $project->id)->value('catalog_version_id');
            if (! $locked->threat()->where('project_id', $project->id)->where('is_active', true)->where(fn ($q) => $q->where('catalog_version_id', $currentCatalog)->orWhereNull('threat_catalog_entry_id'))->exists()) {
                throw ValidationException::withMessages(['threat' => 'Die Gefährdung ist nicht mehr aktiv.']);
            }
            $old = $locked->status;
            $locked->update(['status' => $status, 'rationale' => $status === ThreatReviewStatus::NotApplicable ? $rationale : null, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $this->audit->record('threat_review.changed', $actor, ['project_id' => $project->id, 'target_id' => $locked->id, 'old_status' => $old->value, 'new_status' => $status->value], $project->organization_id);

            return $locked;
        });
    }

    public function isComplete(IsmsProject $project, DependencyNode $target): bool
    {
        $this->access->target($project, $target);
        $column = $target->type === DependencyNodeType::Process ? 'business_process_id' : 'asset_id';
        $currentCatalog = DB::table('project_risk_contexts')->where('project_id', $project->id)->value('catalog_version_id');
        $query = RiskTargetThreatReview::query()->where('risk_target_threat_reviews.project_id', $project->id)->where($column, $target->id)->whereHas('threat', fn ($q) => $q->where('is_active', true)->where(fn ($threat) => $threat->where('catalog_version_id', $currentCatalog)->orWhereNull('threat_catalog_entry_id')));

        // Task 6 adds current-analysis checks. Until then, an applicable row cannot complete the overview.
        return $query->count() > 0 && ! $query->whereIn('status', [ThreatReviewStatus::Unreviewed->value, ThreatReviewStatus::Applicable->value])->exists();
    }
}
