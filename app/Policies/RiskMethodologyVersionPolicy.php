<?php

namespace App\Policies;

use App\Models\RiskMethodologyVersion;
use App\Models\User;

class RiskMethodologyVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProjectRiskPolicy::class)->viewGlobal($user);
    }

    public function clone(User $user, RiskMethodologyVersion $version): bool
    {
        return app(ProjectRiskPolicy::class)->publishGlobal($user);
    }

    public function update(User $user, RiskMethodologyVersion $version): bool
    {
        return app(ProjectRiskPolicy::class)->publishGlobal($user);
    }

    public function publish(User $user, RiskMethodologyVersion $version): bool
    {
        return app(ProjectRiskPolicy::class)->publishGlobal($user);
    }
}
