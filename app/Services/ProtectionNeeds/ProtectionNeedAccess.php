<?php

namespace App\Services\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ProtectionNeedAccess
{
    public function writer(User $actor): User
    {
        $current = User::query()->with('organization')->find($actor->id);
        if (! $current || ! $current->is_active || ! $current->organization?->is_active || $current->organization->organization_type !== 'internal' || ! in_array($current->role, [UserRole::Admin, UserRole::Consultant], true)) {
            throw ValidationException::withMessages(['actor' => 'Ein aktiver interner Administrator oder Berater ist erforderlich.']);
        }

        return $current;
    }

    public function project(IsmsProject $project): IsmsProject
    {
        $current = IsmsProject::query()->with('organization')->whereKey($project->id)->lockForUpdate()->first();
        if (! $current || ! $current->organization?->is_active || $current->organization->organization_type !== 'customer' || ! in_array($current->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            throw ValidationException::withMessages(['project' => 'Ein bearbeitbares Kundenprojekt ist erforderlich.']);
        }

        return $current;
    }

    public function target(IsmsProject $project, DependencyNode $node, bool $requireActive = true): void
    {
        if ($node->projectId !== $project->id) {
            throw ValidationException::withMessages(['target' => 'Das Ziel gehört nicht zu diesem Projekt.']);
        }
        $query = $node->type === DependencyNodeType::Process ? BusinessProcess::query() : Asset::query();
        $query->whereKey($node->id)->where('project_id', $project->id);
        if ($requireActive) {
            $query->where('is_active', true);
        }
        if (! $query->exists()) {
            throw ValidationException::withMessages(['target' => 'Das Ziel ist inaktiv oder gehört nicht zu diesem Projekt.']);
        }
    }
}
