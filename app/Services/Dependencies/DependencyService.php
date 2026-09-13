<?php

namespace App\Services\Dependencies;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyImportance;
use App\Enums\DependencyNodeType;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Registers\RegisterKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DependencyService
{
    public function __construct(
        private readonly DependencyCycleDetector $cycleDetector,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(IsmsProject $project, array $attributes, User $actor, bool $audit = true): DependencyEdge
    {
        $data = $this->validateCreate($attributes);

        return DB::transaction(function () use ($project, $data, $actor, $audit): DependencyEdge {
            $lockedProject = $this->lockedWritableProject($project, $actor);
            $source = $this->resolveNode($lockedProject, $data['source_type'], $data['source_key']);
            $target = $this->resolveNode($lockedProject, $data['target_type'], $data['target_key']);
            $this->assertShape($source, $target);
            $this->assertActive($source, 'source_key');
            $this->assertActive($target, 'target_key');
            $pair = $this->pairColumns($source, $target);
            $matches = DependencyEdge::query()->where('project_id', $lockedProject->id)->where($pair)->oldest('created_at')->lockForUpdate()->get();
            if ($matches->contains(fn (DependencyEdge $edge): bool => $edge->is_active)) {
                $this->reject('dependency', 'Diese aktive Abhängigkeit besteht bereits.');
            }
            $existing = $matches->first();
            $this->cycleDetector->assertCanConnect($lockedProject, $source, $target);
            if ($existing instanceof DependencyEdge) {
                $oldActive = $existing->is_active;
                $existing->fill(['importance' => $data['importance'], 'reason' => $data['reason'], 'is_active' => true]);
                $changed = array_values(array_intersect(['importance', 'reason'], array_keys($existing->getDirty())));
                $existing->save();
                if ($audit) {
                    $this->audit->record('dependency.status_changed', $actor, [...$this->auditContext($lockedProject, $existing, $source, $target), 'old_active' => $oldActive ? 'true' : 'false', 'new_active' => 'true', 'changed_fields' => $changed], $lockedProject->organization_id);
                }

                return $existing;
            }
            $edge = DependencyEdge::query()->create([...$pair, 'project_id' => $lockedProject->id, 'importance' => $data['importance'], 'reason' => $data['reason'], 'is_active' => true, 'created_by' => $actor->id]);
            if ($audit) {
                $this->audit->record('dependency.created', $actor, $this->auditContext($lockedProject, $edge, $source, $target), $lockedProject->organization_id);
            }

            return $edge->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(DependencyEdge $edge, array $attributes, User $actor, string $expectedUpdatedAt, bool $audit = true): DependencyEdge
    {
        $data = Validator::make($attributes, ['importance' => ['required', Rule::enum(DependencyImportance::class)], 'reason' => ['nullable', 'string', 'max:1000']])->validate();

        return DB::transaction(function () use ($edge, $data, $actor, $expectedUpdatedAt, $audit): DependencyEdge {
            $project = $this->lockedWritableProject($edge->project, $actor);
            $locked = $this->lockedEdge($project, $edge);
            if (! $locked->updated_at->equalTo(CarbonImmutable::parse($expectedUpdatedAt))) {
                $this->reject('updated_at', 'Der Datensatz wurde zwischenzeitlich geändert.');
            }
            $locked->fill($data);
            $changed = array_values(array_intersect(['importance', 'reason'], array_keys($locked->getDirty())));
            if ($changed === []) {
                return $locked;
            }
            $locked->save();
            if ($audit) {
                [$source, $target] = $this->edgeNodes($locked);
                $this->audit->record('dependency.updated', $actor, [...$this->auditContext($project, $locked, $source, $target), 'changed_fields' => $changed], $project->organization_id);
            }

            return $locked;
        });
    }

    public function changeStatus(DependencyEdge $edge, bool $active, User $actor, bool $audit = true): DependencyEdge
    {
        return DB::transaction(function () use ($edge, $active, $actor, $audit): DependencyEdge {
            $project = $this->lockedWritableProject($edge->project, $actor);
            $locked = $this->lockedEdge($project, $edge);
            if ($locked->is_active === $active) {
                return $locked;
            }
            [$source, $target] = $this->edgeNodes($locked);
            if ($active) {
                $this->assertActive($source, 'source_key');
                $this->assertActive($target, 'target_key');
                $duplicate = DependencyEdge::query()->where('project_id', $project->id)->where($this->pairColumns($source, $target))->where('is_active', true)->whereKeyNot($locked->id)->exists();
                if ($duplicate) {
                    $this->reject('dependency', 'Diese aktive Abhängigkeit besteht bereits.');
                }
                $this->cycleDetector->assertCanConnect($project, $source, $target);
            }
            $old = $locked->is_active;
            $locked->update(['is_active' => $active]);
            if ($audit) {
                $this->audit->record('dependency.status_changed', $actor, [...$this->auditContext($project, $locked, $source, $target), 'old_active' => $old ? 'true' : 'false', 'new_active' => $active ? 'true' : 'false'], $project->organization_id);
            }

            return $locked;
        });
    }

    /**
     * Apply one already validated row while the caller holds the project lock and
     * has checked the complete final graph. Import summaries own the audit event.
     *
     * @param  array{importance: string, reason: string|null, active: bool}  $attributes
     */
    public function upsertFromImport(
        IsmsProject $project,
        DependencyNode $source,
        DependencyNode $target,
        array $attributes,
        ?DependencyEdge $existing,
        User $actor,
    ): DependencyEdge {
        if ($source->projectId !== $project->id || $target->projectId !== $project->id) {
            $this->reject('dependency', 'Die Abhängigkeit gehört nicht zu diesem Projekt.');
        }

        $pair = $this->pairColumns($source, $target);
        if ($existing instanceof DependencyEdge) {
            if ($existing->project_id !== $project->id || array_filter(
                array_keys($pair),
                fn (string $column): bool => $existing->getAttribute($column) !== $pair[$column],
            ) !== []) {
                $this->reject('dependency', 'Die Abhängigkeit gehört nicht zu diesem Projekt.');
            }
            $existing->fill([
                'importance' => $attributes['importance'],
                'reason' => $attributes['reason'],
                'is_active' => $attributes['active'],
            ]);
            if ($existing->isDirty()) {
                $existing->save();
            }

            return $existing;
        }

        return DependencyEdge::query()->create([
            ...$pair,
            'project_id' => $project->id,
            'importance' => $attributes['importance'],
            'reason' => $attributes['reason'],
            'is_active' => $attributes['active'],
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validateCreate(array $attributes): array
    {
        $data = Validator::make($attributes, [
            'source_type' => ['required', Rule::enum(DependencyNodeType::class)],
            'source_key' => ['required', 'string'],
            'target_type' => ['required', Rule::enum(DependencyNodeType::class)],
            'target_key' => ['required', 'string'],
            'importance' => ['required', Rule::enum(DependencyImportance::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $data['source_key'] = RegisterKey::normalize($data['source_key']);
        $data['target_key'] = RegisterKey::normalize($data['target_key']);

        return $data;
    }

    private function resolveNode(IsmsProject $project, string $type, string $key): DependencyNode
    {
        if ($type === DependencyNodeType::Process->value) {
            $record = BusinessProcess::query()->where('project_id', $project->id)->where('key', $key)->lockForUpdate()->first();
            abort_unless($record instanceof BusinessProcess, 404);

            return DependencyNode::process($record);
        }
        $record = Asset::query()->where('project_id', $project->id)->where('key', $key)->lockForUpdate()->first();
        abort_unless($record instanceof Asset, 404);

        return DependencyNode::asset($record);
    }

    private function assertShape(DependencyNode $source, DependencyNode $target): void
    {
        if ($source->identity() === $target->identity()) {
            $this->reject('target_key', 'Eine Selbstabhängigkeit ist nicht zulässig.');
        }
        if ($source->type === DependencyNodeType::Asset && $target->type === DependencyNodeType::Process) {
            $this->reject('target_type', 'Assets dürfen nicht von Prozessen abhängen.');
        }
    }

    private function assertActive(DependencyNode $node, string $field): void
    {
        $active = $node->type === DependencyNodeType::Process
            ? BusinessProcess::query()->whereKey($node->id)->where('is_active', true)->exists()
            : Asset::query()->whereKey($node->id)->where('is_active', true)->exists();
        if (! $active) {
            $this->reject($field, 'Der Endpunkt ist inaktiv.');
        }
    }

    /** @return array{source_process_id: string|null, source_asset_id: string|null, target_process_id: string|null, target_asset_id: string|null} */
    private function pairColumns(DependencyNode $source, DependencyNode $target): array
    {
        return [
            'source_process_id' => $source->type === DependencyNodeType::Process ? $source->id : null,
            'source_asset_id' => $source->type === DependencyNodeType::Asset ? $source->id : null,
            'target_process_id' => $target->type === DependencyNodeType::Process ? $target->id : null,
            'target_asset_id' => $target->type === DependencyNodeType::Asset ? $target->id : null,
        ];
    }

    /** @return array{DependencyNode, DependencyNode} */
    private function edgeNodes(DependencyEdge $edge): array
    {
        $edge->loadMissing(['sourceProcess', 'sourceAsset', 'targetProcess', 'targetAsset']);
        $source = $edge->sourceProcess !== null ? DependencyNode::process($edge->sourceProcess) : DependencyNode::asset($edge->sourceAsset);
        $target = $edge->targetProcess !== null ? DependencyNode::process($edge->targetProcess) : DependencyNode::asset($edge->targetAsset);

        return [$source, $target];
    }

    /** @return array<string, string> */
    private function auditContext(IsmsProject $project, DependencyEdge $edge, DependencyNode $source, DependencyNode $target): array
    {
        return ['project_id' => $project->id, 'dependency_edge_id' => $edge->id, 'source_type' => $source->type->value, 'source_id' => $source->id, 'source_key' => $source->key, 'target_type' => $target->type->value, 'target_id' => $target->id, 'target_key' => $target->key, 'importance' => $edge->importance->value];
    }

    private function lockedWritableProject(IsmsProject $project, User $actor): IsmsProject
    {
        $actor->loadMissing('organization');
        if (! $actor->is_active || $actor->organization?->organization_type !== 'internal' || ! in_array($actor->role, [UserRole::Admin, UserRole::Consultant], true)) {
            $this->reject('dependency', 'Die Aktion ist nicht zulässig.');
        }
        $locked = IsmsProject::query()->with('organization')->whereKey($project->id)->lockForUpdate()->first();
        if (! $locked instanceof IsmsProject || $locked->organization?->organization_type !== 'customer' || ! $locked->organization->is_active || ! in_array($locked->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            $this->reject('dependency', 'Das Projekt ist nicht beschreibbar.');
        }

        return $locked;
    }

    private function lockedEdge(IsmsProject $project, DependencyEdge $edge): DependencyEdge
    {
        $locked = DependencyEdge::query()->whereKey($edge->id)->where('project_id', $project->id)->lockForUpdate()->first();
        if (! $locked instanceof DependencyEdge) {
            $this->reject('dependency', 'Die Abhängigkeit gehört nicht zu diesem Projekt.');
        }

        return $locked;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
