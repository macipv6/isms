<?php

namespace App\Services\Bcm;

use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Policies\ProjectBcmPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BcmProjectAccess
{
    public function __construct(private readonly ProjectBcmPolicy $policy) {}

    public function view(IsmsProject $project, User $actor): bool
    {
        return $this->policy->view($actor, $project);
    }

    /** Must be called within the mutation transaction. */
    public function lockedWritable(IsmsProject $project, User $actor): IsmsProject
    {
        $project = IsmsProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        if (! $actor) {
            throw new AuthorizationException;
        }
        Organization::query()->whereIn('id', [$project->organization_id, $actor->organization_id])->orderBy('id')->lockForUpdate()->get();
        if (! $this->policy->write($actor, $project)) {
            throw new AuthorizationException;
        }

        return $project;
    }

    public function assertVersion(Model $model, int $expectedLockVersion): void
    {
        if ($expectedLockVersion < 1 || (int) $model->getAttribute('lock_version') !== $expectedLockVersion) {
            throw new ConflictHttpException('Die Daten wurden geändert. Bitte laden Sie sie neu.');
        }
    }
}
