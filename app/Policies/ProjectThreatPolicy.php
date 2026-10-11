<?php

namespace App\Policies;

use App\Models\IsmsProject;
use App\Models\ProjectThreat;
use App\Models\User;

class ProjectThreatPolicy
{
    public function create(User $user, IsmsProject $project): bool
    {
        return app(ProjectRiskPolicy::class)->write($user, $project);
    }

    public function update(User $user, ProjectThreat $threat): bool
    {
        return app(ProjectRiskPolicy::class)->write($user, $threat->context->project);
    }
}
