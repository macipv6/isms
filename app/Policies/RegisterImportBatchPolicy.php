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
        $currentUser = User::query()->with('organization')->find($user->id);

        return $currentUser?->is_active
            && $currentUser->organization?->organization_type === 'internal'
            && $currentUser->organization->is_active
            && in_array($currentUser->getRawOriginal('role'), [UserRole::Admin->value, UserRole::Consultant->value], true);
    }

    private function writable(IsmsProject $project): bool
    {
        $project->loadMissing('organization');

        return $project->organization?->organization_type === 'customer'
            && $project->organization->is_active
            && in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Active], true);
    }
}
