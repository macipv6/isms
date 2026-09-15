<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\IsmsProject;
use App\Models\User;

class AssetPolicy
{
    public function create(User $user, IsmsProject $project): bool
    {
        return $this->write($user, $project);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->write($user, $asset->project);
    }

    public function changeStatus(User $user, Asset $asset): bool
    {
        return $this->write($user, $asset->project);
    }

    private function write(User $user, IsmsProject $project): bool
    {
        $user->loadMissing('organization');
        $project->loadMissing('organization');

        return $user->is_active
            && $user->organization?->organization_type === 'internal'
            && in_array($user->role, [UserRole::Admin, UserRole::Consultant], true)
            && $project->organization?->organization_type === 'customer'
            && $project->organization->is_active
            && in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Active], true);
    }
}
