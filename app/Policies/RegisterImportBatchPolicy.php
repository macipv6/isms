<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\RegisterImportStatus;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\RegisterImportBatch;
use App\Models\User;

class RegisterImportBatchPolicy
{
    public function create(User $user, IsmsProject $project): bool
    {
        return $this->internal($user) && $this->writable($project);
    }

    public function view(User $user, RegisterImportBatch $batch): bool
    {
        return $this->internal($user) && $batch->created_by === $user->id;
    }

    public function confirm(User $user, RegisterImportBatch $batch): bool
    {
        return $this->view($user, $batch)
            && $this->writable($batch->project)
            && $batch->status === RegisterImportStatus::Pending
            && $batch->expires_at->isFuture();
    }

    private function internal(User $user): bool
    {
        $user->loadMissing('organization');

        return $user->is_active
            && $user->organization?->organization_type === 'internal'
            && in_array($user->role, [UserRole::Admin, UserRole::Consultant], true);
    }

    private function writable(IsmsProject $project): bool
    {
        $project->loadMissing('organization');

        return $project->organization?->organization_type === 'customer'
            && $project->organization->is_active
            && in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Active], true);
    }
}
