<?php

namespace App\Services\Registers;

use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssetService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes */
    public function create(IsmsProject $project, array $attributes, User $actor, bool $audit = true): Asset
    {
        $data = $this->validate($attributes, true);
        $data['key'] = RegisterKey::normalize($data['key']);

        return DB::transaction(function () use ($project, $data, $actor, $audit): Asset {
            $lockedProject = $this->project($project, $actor);
            if (Asset::query()->where('project_id', $lockedProject->id)->where('key', $data['key'])->exists()) {
                $this->reject('key', 'Dieser Schlüssel wird bereits verwendet.');
            }
            $active = $data['active'];
            unset($data['active']);
            $asset = Asset::query()->create([...$data, 'project_id' => $lockedProject->id, 'is_active' => $active, 'created_by' => $actor->id]);
            if ($audit) {
                $this->audit->record('asset.created', $actor, ['project_id' => $lockedProject->id, 'asset_id' => $asset->id, 'key' => $asset->key], $lockedProject->organization_id);
            }

            return $asset;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Asset $asset, array $attributes, User $actor, string $expectedUpdatedAt, bool $audit = true): Asset
    {
        $data = $this->validate($attributes, false);

        return DB::transaction(function () use ($asset, $data, $actor, $expectedUpdatedAt, $audit): Asset {
            $project = $this->project($asset->project, $actor);
            $locked = $this->locked($project, $asset);
            if ($locked->updated_at->toIso8601String() !== $expectedUpdatedAt) {
                $this->reject('updated_at', 'Der Datensatz wurde zwischenzeitlich geändert.');
            }
            $locked->fill($data);
            $changed = array_values(array_intersect(['name', 'type', 'description', 'owner_name', 'owner_email'], array_keys($locked->getDirty())));
            if ($changed === []) {
                return $locked;
            }
            $locked->save();
            if ($audit) {
                $this->audit->record('asset.updated', $actor, ['project_id' => $project->id, 'asset_id' => $locked->id, 'key' => $locked->key, 'changed_fields' => $changed], $project->organization_id);
            }

            return $locked;
        });
    }

    public function changeStatus(Asset $asset, bool $active, User $actor, bool $audit = true): Asset
    {
        return DB::transaction(function () use ($asset, $active, $actor, $audit): Asset {
            $project = $this->project($asset->project, $actor);
            $locked = $this->locked($project, $asset);
            if ($locked->is_active === $active) {
                return $locked;
            }
            $old = $locked->is_active;
            $locked->update(['is_active' => $active]);
            if ($audit) {
                $this->audit->record('asset.status_changed', $actor, ['project_id' => $project->id, 'asset_id' => $locked->id, 'key' => $locked->key, 'old_active' => $old ? 'true' : 'false', 'new_active' => $active ? 'true' : 'false'], $project->organization_id);
            }

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $rules = ['name' => ['required', 'string', 'max:160'], 'type' => ['required', Rule::enum(AssetType::class)], 'description' => ['nullable', 'string', 'max:4000'], 'owner_name' => ['nullable', 'string', 'max:160'], 'owner_email' => ['nullable', 'email:rfc', 'max:254']];
        if ($creating) {
            $rules['key'] = ['required', 'string'];
            $rules['active'] = ['sometimes', 'boolean'];
        }
        $validated = Validator::make($data, $rules)->validate();
        if ($creating) {
            $validated['active'] = $validated['active'] ?? true;
        }

        return $validated;
    }

    private function project(IsmsProject $project, User $actor): IsmsProject
    {
        $actor->loadMissing('organization');
        if (! $actor->is_active || $actor->organization?->organization_type !== 'internal' || ! in_array($actor->role, [UserRole::Admin, UserRole::Consultant], true)) {
            $this->reject('asset', 'Die Aktion ist nicht zulässig.');
        }
        $locked = IsmsProject::query()->with('organization')->whereKey($project->id)->lockForUpdate()->first();
        if (! $locked instanceof IsmsProject || $locked->organization?->organization_type !== 'customer' || ! $locked->organization->is_active || ! in_array($locked->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            $this->reject('asset', 'Das Projekt ist nicht beschreibbar.');
        }

        return $locked;
    }

    private function locked(IsmsProject $project, Asset $asset): Asset
    {
        $locked = Asset::query()->whereKey($asset->id)->where('project_id', $project->id)->lockForUpdate()->first();
        if (! $locked instanceof Asset) {
            $this->reject('asset', 'Das Asset gehört nicht zu diesem Projekt.');
        }

        return $locked;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
