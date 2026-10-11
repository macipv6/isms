<?php

namespace App\Policies;

use App\Models\ProtectionNeed;
use App\Models\ProtectionNeedDowngradeRequest;
use App\Models\User;

class ProtectionNeedPolicy
{
    public function view(User $user, ProtectionNeed $need): bool
    {
        return app(ProjectRiskPolicy::class)->view($user, $need->project);
    }

    public function update(User $user, ProtectionNeed $need): bool
    {
        return app(ProjectRiskPolicy::class)->write($user, $need->project);
    }

    public function requestDowngrade(User $user, ProtectionNeed $need): bool
    {
        return $this->update($user, $need);
    }

    public function decideDowngrade(User $user, ProtectionNeedDowngradeRequest $request): bool
    {
        return app(ProjectRiskPolicy::class)->write($user, $request->need->project)
            && $request->requested_by !== $user->id;
    }

    public function recordExternalDowngrade(User $user, ProtectionNeedDowngradeRequest $request): bool
    {
        return app(ProjectRiskPolicy::class)->write($user, $request->need->project);
    }
}
