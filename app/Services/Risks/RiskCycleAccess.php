<?php

namespace App\Services\Risks;

use App\Enums\RiskCycleStatus;
use App\Models\IsmsProject;
use App\Models\RiskAnalysisCycle;
use App\Models\User;
use App\Services\ProtectionNeeds\ProtectionNeedAccess;
use Illuminate\Validation\ValidationException;

class RiskCycleAccess
{
    public function __construct(private readonly ProtectionNeedAccess $access) {}

    /** @param list<RiskCycleStatus> $allowed
     * @return array{IsmsProject, RiskAnalysisCycle, User}
     */
    public function lock(RiskAnalysisCycle $cycle, User $actor, array $allowed, ?int $expectedVersion = null): array
    {
        $actor = $this->access->writer($actor);
        $project = $this->access->project(IsmsProject::query()->findOrFail($cycle->project_id));
        $locked = RiskAnalysisCycle::query()->whereKey($cycle->id)->where('project_id', $project->id)->lockForUpdate()->first();
        if (! $locked || ! $locked->is_current || $locked->needs_review || ! in_array($locked->status, $allowed, true)
            || ($expectedVersion !== null && $locked->lock_version !== $expectedVersion)) {
            throw ValidationException::withMessages(['cycle' => 'Der Risikozyklus wurde geändert. Bitte laden Sie ihn neu.']);
        }

        return [$project, $locked, $actor];
    }

    /** @return list<RiskCycleStatus> */
    public function treatmentStatuses(): array
    {
        return [RiskCycleStatus::InherentAssessed, RiskCycleStatus::TreatmentInProgress];
    }
}
