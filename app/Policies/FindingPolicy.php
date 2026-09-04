<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\User;

class FindingPolicy
{
    public function view(User $user, Finding $finding): bool
    {
        return $this->canRead($user, $finding->project);
    }

    public function propose(User $user, IsmsProject $project): bool
    {
        return $this->canWrite($user, $project);
    }

    public function update(User $user, Finding $finding): bool
    {
        return $this->canWrite($user, $finding->project);
    }

    public function decide(User $user, Finding $finding): bool
    {
        return $this->canWrite($user, $finding->project);
    }

    public function close(User $user, Finding $finding): bool
    {
        return $this->canWrite($user, $finding->project);
    }

    private function canRead(User $user, IsmsProject $project): bool
    {
        $user->loadMissing('organization');
        $project->loadMissing('organization');

        return $user->is_active
            && $user->organization?->organization_type === 'internal'
            && in_array($user->role, [UserRole::Admin, UserRole::Consultant], true)
            && $project->organization?->organization_type === 'customer';
    }

    private function canWrite(User $user, IsmsProject $project): bool
    {
        return $this->canRead($user, $project)
            && $project->organization?->is_active
            && in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Active], true);
    }
}
