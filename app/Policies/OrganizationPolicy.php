<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->is_active && $organization->organization_type === 'customer';
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::Admin;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->create($user) && $organization->organization_type === 'customer';
    }

    public function deactivate(User $user, Organization $organization): bool
    {
        return $this->update($user, $organization);
    }
}
