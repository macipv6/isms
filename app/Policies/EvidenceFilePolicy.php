<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\User;

class EvidenceFilePolicy
{
    public function view(User $user, EvidenceFile|IsmsProject $resource): bool
    {
        return $this->canRead($user, $this->project($resource));
    }

    public function upload(User $user, IsmsProject $project): bool
    {
        return $this->canWrite($user, $project);
    }

    public function link(User $user, IsmsProject $project): bool
    {
        return $this->canWrite($user, $project);
    }

    public function review(User $user, EvidenceFile|IsmsProject $resource): bool
    {
        return $this->canWrite($user, $this->project($resource));
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

    private function project(EvidenceFile|IsmsProject $resource): IsmsProject
    {
        return $resource instanceof EvidenceFile ? $resource->project : $resource;
    }
}
