<?php

namespace App\Policies;

use App\Models\IsmsProject;
use App\Models\RiskAnalysisCycle;
use App\Models\User;

class RiskAnalysisPolicy
{
    public function create(User $user, IsmsProject $project): bool
    {
        return app(ProjectRiskPolicy::class)->write($user, $project);
    }

    public function update(User $user, RiskAnalysisCycle $cycle): bool
    {
        return app(ProjectRiskPolicy::class)->write($user, IsmsProject::query()->findOrFail($cycle->project_id));
    }

    public function decideInternal(User $user, RiskAnalysisCycle $cycle): bool
    {
        return $this->update($user, $cycle)
            && $cycle->currentResidualAssessment()->where('assessed_by', $user->id)->doesntExist();
    }

    public function decideExternal(User $user, RiskAnalysisCycle $cycle): bool
    {
        return $this->update($user, $cycle);
    }
}
