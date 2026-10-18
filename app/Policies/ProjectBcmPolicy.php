<?php

namespace App\Policies;

use App\Models\IsmsProject;
use App\Models\User;

class ProjectBcmPolicy
{
    public function view(User $user, IsmsProject $project): bool
    {
        return app(ProjectRiskPolicy::class)->view($user, $project);
    }

    public function write(User $user, IsmsProject $project): bool
    {
        return app(ProjectRiskPolicy::class)->write($user, $project);
    }
}
