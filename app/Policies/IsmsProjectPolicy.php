<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;

class IsmsProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, IsmsProject $project): bool
    {
        return $user->is_active
            && $project->organization->organization_type === 'customer';
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->is_active
            && $user->role === UserRole::Admin
            && $organization->organization_type === 'customer'
            && $organization->is_active;
    }

    public function update(User $user, IsmsProject $project): bool
    {
        return $user->is_active
            && $user->role === UserRole::Admin
            && $project->organization->organization_type === 'customer'
            && $project->organization->is_active;
    }

    public function viewAssessment(User $user, IsmsProject $project): bool
    {
        return $this->view($user, $project)
            && in_array($user->role, [UserRole::Admin, UserRole::Consultant], true);
    }

    public function startAssessment(User $user, IsmsProject $project): bool
    {
        return $this->canWriteAssessment($user, $project);
    }

    public function answerAssessment(User $user, IsmsProject $project): bool
    {
        return $this->canWriteAssessment($user, $project);
    }

    private function canWriteAssessment(User $user, IsmsProject $project): bool
    {
        return $this->viewAssessment($user, $project)
            && $project->organization->is_active;
    }
}
