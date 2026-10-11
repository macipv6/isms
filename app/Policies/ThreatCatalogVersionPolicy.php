<?php

namespace App\Policies;

use App\Models\ThreatCatalogVersion;
use App\Models\User;

class ThreatCatalogVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProjectRiskPolicy::class)->viewGlobal($user);
    }

    public function clone(User $user, ThreatCatalogVersion $version): bool
    {
        return app(ProjectRiskPolicy::class)->publishGlobal($user);
    }

    public function update(User $user, ThreatCatalogVersion $version): bool
    {
        return app(ProjectRiskPolicy::class)->publishGlobal($user);
    }

    public function publish(User $user, ThreatCatalogVersion $version): bool
    {
        return app(ProjectRiskPolicy::class)->publishGlobal($user);
    }
}
