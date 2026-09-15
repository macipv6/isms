<?php

namespace App\Services\Registers;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BusinessProcessService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes */
    public function create(IsmsProject $project, array $attributes, User $actor, bool $audit = true): BusinessProcess
    {
        $data = $this->validate($attributes, true);
        $data['key'] = RegisterKey::normalize($data['key']);

        return DB::transaction(function () use ($project, $data, $actor, $audit): BusinessProcess {
            $lockedProject = $this->lockedWritableProject($project, $actor);
            if (BusinessProcess::query()->where('project_id', $lockedProject->id)->where('key', $data['key'])->exists()) {
                $this->reject('key', 'Dieser Schlüssel wird bereits verwendet.');
            }
            $active = $data['active'];
            unset($data['active']);
            $process = BusinessProcess::query()->create([...$data, 'project_id' => $lockedProject->id, 'is_active' => $active, 'created_by' => $actor->id]);
            if ($audit) {
                $this->audit->record('business_process.created', $actor, ['project_id' => $lockedProject->id, 'business_process_id' => $process->id, 'key' => $process->key], $lockedProject->organization_id);
            }

            $process->refresh();

            return $process;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(BusinessProcess $process, array $attributes, User $actor, string $expectedUpdatedAt, bool $audit = true): BusinessProcess
    {
        $data = $this->validate($attributes, false);

        return DB::transaction(function () use ($process, $data, $actor, $expectedUpdatedAt, $audit): BusinessProcess {
            $project = $this->lockedWritableProject($process->project, $actor);
            $locked = $this->locked($project, $process);
            if (! $locked->updated_at->equalTo(CarbonImmutable::parse($expectedUpdatedAt))) {
                $this->reject('updated_at', 'Der Datensatz wurde zwischenzeitlich geändert.');
            }
            $locked->fill($data);
            $changed = array_values(array_intersect(['name', 'description', 'owner_name', 'owner_email'], array_keys($locked->getDirty())));
            if ($changed === []) {
                return $locked;
            }
            $locked->save();
            if ($audit) {
                $this->audit->record('business_process.updated', $actor, ['project_id' => $project->id, 'business_process_id' => $locked->id, 'key' => $locked->key, 'changed_fields' => $changed], $project->organization_id);
            }

            return $locked;
        });
    }

    public function changeStatus(BusinessProcess $process, bool $active, User $actor, bool $audit = true): BusinessProcess
    {
        return DB::transaction(function () use ($process, $active, $actor, $audit): BusinessProcess {
            $project = $this->lockedWritableProject($process->project, $actor);
            $locked = $this->locked($project, $process);
            if ($locked->is_active === $active) {
                return $locked;
            }
            $old = $locked->is_active;
            $locked->update(['is_active' => $active]);
            if ($audit) {
                $this->audit->record('business_process.status_changed', $actor, ['project_id' => $project->id, 'business_process_id' => $locked->id, 'key' => $locked->key, 'old_active' => $old ? 'true' : 'false', 'new_active' => $active ? 'true' : 'false'], $project->organization_id);
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
        $rules = ['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:4000'], 'owner_name' => ['nullable', 'string', 'max:160'], 'owner_email' => ['nullable', 'email:rfc', 'max:254']];
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

    private function lockedWritableProject(IsmsProject $project, User $actor): IsmsProject
    {
        $actor->loadMissing('organization');
        if (! $actor->is_active || $actor->organization?->organization_type !== 'internal' || ! in_array($actor->role, [UserRole::Admin, UserRole::Consultant], true)) {
            $this->reject('process', 'Die Aktion ist nicht zulässig.');
        }
        $locked = IsmsProject::query()->with('organization')->whereKey($project->id)->lockForUpdate()->first();
        if (! $locked instanceof IsmsProject || $locked->organization?->organization_type !== 'customer' || ! $locked->organization->is_active || ! in_array($locked->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            $this->reject('process', 'Das Projekt ist nicht beschreibbar.');
        }

        return $locked;
    }

    private function locked(IsmsProject $project, BusinessProcess $process): BusinessProcess
    {
        $locked = BusinessProcess::query()->whereKey($process->id)->where('project_id', $project->id)->lockForUpdate()->first();
        if (! $locked instanceof BusinessProcess) {
            $this->reject('process', 'Der Prozess gehört nicht zu diesem Projekt.');
        }

        return $locked;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
