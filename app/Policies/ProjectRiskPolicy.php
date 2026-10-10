<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\User;

class ProjectRiskPolicy
{
    public function view(User $user, IsmsProject $project): bool
    {
        $currentUser = User::query()->with('organization')->find($user->id);
        $currentProject = IsmsProject::query()->with('organization')->find($project->id);

        return $currentUser?->is_active
            && $currentUser->organization?->organization_type === 'internal'
            && $currentUser->organization->is_active
            && in_array($currentUser->getRawOriginal('role'), [UserRole::Admin->value, UserRole::Consultant->value], true)
            && $currentProject?->organization?->organization_type === 'customer';
    }

    public function write(User $user, IsmsProject $project): bool
    {
        $currentProject = IsmsProject::query()->with('organization')->find($project->id);

        return $this->view($user, $project)
            && $currentProject?->organization?->is_active
            && in_array($currentProject->status, [ProjectStatus::Draft, ProjectStatus::Active], true);
    }

    public function publishGlobal(User $user): bool
    {
        $user = User::query()->with('organization')->find($user->id);

        return $user?->is_active && $user->organization?->organization_type === 'internal' && $user->organization->is_active && $user->getRawOriginal('role') === UserRole::Admin->value;
    }

    public function viewGlobal(User $user): bool
    {
        $user = User::query()->with('organization')->find($user->id);

        return $user?->is_active && $user->organization?->organization_type === 'internal'
            && $user->organization->is_active
            && in_array($user->getRawOriginal('role'), [UserRole::Admin->value, UserRole::Consultant->value], true);
    }
}
