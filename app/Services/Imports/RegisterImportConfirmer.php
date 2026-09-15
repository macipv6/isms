<?php

namespace App\Services\Imports;

use App\Data\Dependencies\DependencyNode;
use App\Enums\ProjectStatus;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Dependencies\DependencyCycleDetector;
use App\Services\Dependencies\DependencyService;
use App\Services\Registers\AssetService;
use App\Services\Registers\BusinessProcessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterImportConfirmer
{
    public function __construct(
        private readonly RegisterRowValidator $rowValidator,
        private readonly BusinessProcessService $processes,
        private readonly AssetService $assets,
        private readonly AuditLogger $audit,
        private readonly RegisterImportStateFingerprint $stateFingerprint,
        private readonly DependencyCycleDetector $cycleDetector,
        private readonly DependencyService $dependencies,
    ) {}

    public function confirm(RegisterImportBatch $batch, User $actor): RegisterImportBatch
    {
        try {
            return DB::transaction(function () use ($batch, $actor): RegisterImportBatch {
                $lockedBatch = RegisterImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
                if (! $lockedBatch instanceof RegisterImportBatch) {
                    $this->reject();
                }
                $this->assertConfirmable($lockedBatch, $actor);
                $project = $this->lockedWritableProject($lockedBatch);
                $rows = $this->canonicalRows($lockedBatch);
                if ($lockedBatch->kind === RegisterImportKind::Dependencies) {
                    $state = $this->lockedDependencyState($project, $rows);
                    $counts = $this->dependencyCounts($state['rows']);
                    if (! $this->sameCounts($counts, $lockedBatch->summary['counts'] ?? null)
                        || ! $this->sameDependencyFingerprint($project, $rows, $state, $lockedBatch->summary['state_fingerprint'] ?? null)) {
                        $this->reject();
                    }
                    $this->assertFinalDependencyGraphIsAcyclic($state);
                    $this->applyDependencies($project, $state['rows'], $actor);
                } else {
                    $existing = $this->lockedExisting($project, $lockedBatch->kind, $rows);
                    $counts = $this->counts($lockedBatch->kind, $rows, $existing);
                    if (! $this->sameCounts($counts, $lockedBatch->summary['counts'] ?? null)
                        || ! $this->sameFingerprint($lockedBatch->kind, $rows, $existing, $lockedBatch->summary['state_fingerprint'] ?? null)) {
                        $this->reject();
                    }
                    $this->apply($project, $lockedBatch->kind, $rows, $existing, $actor);
                }
                $lockedBatch->update([
                    'status' => RegisterImportStatus::Applied,
                    'applied_at' => now('UTC'),
                ]);
                $this->audit->record('register_import.applied', $actor, [
                    'project_id' => $project->id,
                    'import_batch_id' => $lockedBatch->id,
                    'import_kind' => $lockedBatch->kind->value,
                    'row_counts' => $counts,
                ], $project->organization_id);

                return $lockedBatch->refresh();
            });
        } catch (UniqueConstraintViolationException|ValidationException $exception) {
            if ($exception instanceof ValidationException && array_key_exists('import', $exception->errors())) {
                throw $exception;
            }

            $this->reject();
        }
    }

    private function assertConfirmable(RegisterImportBatch $batch, User $actor): void
    {
        $actor->loadMissing('organization');
        if ($batch->created_by !== $actor->id
            || ! $actor->is_active
            || $actor->organization?->organization_type !== 'internal'
            || ! in_array($actor->role, [UserRole::Admin, UserRole::Consultant], true)
            || $batch->status !== RegisterImportStatus::Pending
            || $batch->expires_at->getTimestamp() <= now('UTC')->getTimestamp()) {
            $this->reject();
        }
    }

    private function lockedWritableProject(RegisterImportBatch $batch): IsmsProject
    {
        $project = IsmsProject::query()->whereKey($batch->project_id)->lockForUpdate()->first();
        if (! $project instanceof IsmsProject) {
            $this->reject();
        }
        $organization = Organization::query()->whereKey($project->organization_id)->lockForUpdate()->first();
        if (! $organization instanceof Organization
            || $organization->organization_type !== 'customer'
            || ! $organization->is_active
            || ! in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            $this->reject();
        }
        $project->setRelation('organization', $organization);

        return $project;
    }

    /** @return list<array<string, string|bool|null>> */
    private function canonicalRows(RegisterImportBatch $batch): array
    {
        if (! array_is_list($batch->payload) || $batch->payload === []) {
            $this->reject();
        }

        $rows = [];
        $identifiers = [];
        foreach ($batch->payload as $index => $payloadRow) {
            if (! is_array($payloadRow)) {
                $this->reject();
            }
            $candidate = $payloadRow;
            if (isset($candidate['active']) && is_bool($candidate['active'])) {
                $candidate['active'] = $candidate['active'] ? 'true' : 'false';
            }
            try {
                $row = $this->rowValidator->validate($batch->kind, $candidate, $index + 2)->values;
            } catch (ValidationException) {
                $this->reject();
            }
            $canonicalPayloadRow = $payloadRow;
            ksort($row);
            ksort($canonicalPayloadRow);
            $identifier = $this->rowValidator->identifier($batch->kind, $row);
            if ($row !== $canonicalPayloadRow || $identifier === null || isset($identifiers[$identifier])) {
                $this->reject();
            }
            $identifiers[$identifier] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string|bool|null>>  $rows
     * @return array{
     *     processes: Collection<string, BusinessProcess>,
     *     assets: Collection<string, Asset>,
     *     edges: Collection<int, DependencyEdge>,
     *     rows: list<array{values: array<string, string|bool|null>, source: DependencyNode, target: DependencyNode, existing: DependencyEdge|null}>
     * }
     */
    private function lockedDependencyState(IsmsProject $project, array $rows): array
    {
        $processKeys = [];
        $assetKeys = [];
        foreach ($rows as $row) {
            foreach (['source', 'target'] as $side) {
                if ($row[$side.'_type'] === 'process') {
                    $processKeys[] = $row[$side.'_key'];
                } else {
                    $assetKeys[] = $row[$side.'_key'];
                }
            }
        }

        /** @var Collection<int, DependencyEdge> $edges */
        $edges = collect();
        DependencyEdge::query()
            ->where('project_id', $project->id)
            ->oldest('created_at')
            ->oldest('id')
            ->lockForUpdate()
            ->chunk(500, function ($chunk) use ($edges): void {
                $edges->push(...$chunk);
            });
        [$processes, $assets] = $this->lockedDependencyEndpoints($project, $processKeys, $assetKeys, $edges);

        $existingByPair = [];
        foreach ($edges as $edge) {
            $pairKey = $this->edgePairKey($edge);
            if (! isset($existingByPair[$pairKey]) || $edge->is_active) {
                $existingByPair[$pairKey] = $edge;
            }
        }

        $resolvedRows = [];
        foreach ($rows as $row) {
            $source = $this->resolvedDependencyNode($row['source_type'], $row['source_key'], $processes, $assets);
            $target = $this->resolvedDependencyNode($row['target_type'], $row['target_key'], $processes, $assets);
            $pairKey = $source->identity().'>'.$target->identity();
            $existing = $existingByPair[$pairKey] ?? null;
            if ($this->requiresActiveEndpoints($existing, $row['active'])
                && (! $this->resolvedNodeIsActive($row['source_type'], $row['source_key'], $processes, $assets)
                    || ! $this->resolvedNodeIsActive($row['target_type'], $row['target_key'], $processes, $assets))) {
                $this->reject();
            }
            $resolvedRows[] = [
                'values' => $row,
                'source' => $source,
                'target' => $target,
                'existing' => $existing,
            ];
        }

        return ['processes' => $processes, 'assets' => $assets, 'edges' => $edges, 'rows' => $resolvedRows];
    }

    /**
     * @param  list<string>  $processKeys
     * @param  list<string>  $assetKeys
     * @param  Collection<int, DependencyEdge>  $edges
     * @return array{Collection<string, BusinessProcess>, Collection<string, Asset>}
     */
    private function lockedDependencyEndpoints(IsmsProject $project, array $processKeys, array $assetKeys, Collection $edges): array
    {
        $processIds = [];
        $assetIds = [];
        foreach ($edges as $edge) {
            foreach ([$edge->source_process_id, $edge->target_process_id] as $id) {
                if ($id !== null) {
                    $processIds[] = $id;
                }
            }
            foreach ([$edge->source_asset_id, $edge->target_asset_id] as $id) {
                if ($id !== null) {
                    $assetIds[] = $id;
                }
            }
        }

        /** @var Collection<string, BusinessProcess> $processes */
        $processes = collect();
        foreach (array_chunk(array_values(array_unique($processKeys)), 500) as $keys) {
            foreach (BusinessProcess::query()->where('project_id', $project->id)->whereIn('key', $keys)->lockForUpdate()->get() as $process) {
                $processes->put($process->key, $process);
            }
        }
        foreach (array_chunk(array_values(array_unique($processIds)), 500) as $ids) {
            foreach (BusinessProcess::query()->where('project_id', $project->id)->whereIn('id', $ids)->lockForUpdate()->get() as $process) {
                $processes->put($process->key, $process);
            }
        }

        /** @var Collection<string, Asset> $assets */
        $assets = collect();
        foreach (array_chunk(array_values(array_unique($assetKeys)), 500) as $keys) {
            foreach (Asset::query()->where('project_id', $project->id)->whereIn('key', $keys)->lockForUpdate()->get() as $asset) {
                $assets->put($asset->key, $asset);
            }
        }
        foreach (array_chunk(array_values(array_unique($assetIds)), 500) as $ids) {
            foreach (Asset::query()->where('project_id', $project->id)->whereIn('id', $ids)->lockForUpdate()->get() as $asset) {
                $assets->put($asset->key, $asset);
            }
        }

        return [$processes, $assets];
    }

    /**
     * @param  Collection<string, BusinessProcess>  $processes
     * @param  Collection<string, Asset>  $assets
     */
    private function resolvedDependencyNode(string $type, string $key, Collection $processes, Collection $assets): DependencyNode
    {
        $record = $type === 'process' ? $processes->get($key) : $assets->get($key);
        if (! $record instanceof BusinessProcess && ! $record instanceof Asset) {
            $this->reject();
        }

        return $record instanceof BusinessProcess ? DependencyNode::process($record) : DependencyNode::asset($record);
    }

    /**
     * @param  Collection<string, BusinessProcess>  $processes
     * @param  Collection<string, Asset>  $assets
     */
    private function resolvedNodeIsActive(string $type, string $key, Collection $processes, Collection $assets): bool
    {
        $record = $type === 'process' ? $processes->get($key) : $assets->get($key);

        return ($record instanceof BusinessProcess || $record instanceof Asset) && $record->is_active;
    }

    private function requiresActiveEndpoints(?DependencyEdge $existing, bool $requestedActive): bool
    {
        return $requestedActive && (! $existing instanceof DependencyEdge || ! $existing->is_active);
    }

    /**
     * @param  list<array{values: array<string, string|bool|null>, source: DependencyNode, target: DependencyNode, existing: DependencyEdge|null}>  $rows
     * @return array{new: int, changed: int, unchanged: int, invalid: int}
     */
    private function dependencyCounts(array $rows): array
    {
        $counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'invalid' => 0];
        foreach ($rows as $row) {
            $existing = $row['existing'];
            $values = $row['values'];
            $category = ! $existing instanceof DependencyEdge
                ? 'new'
                : ($existing->importance->value !== $values['importance']
                    || $existing->reason !== $values['reason']
                    || $existing->is_active !== $values['active'] ? 'changed' : 'unchanged');
            $counts[$category]++;
        }

        return $counts;
    }

    /**
     * @param  list<array<string, string|bool|null>>  $rows
     * @param  array{processes: Collection<string, BusinessProcess>, assets: Collection<string, Asset>, edges: Collection<int, DependencyEdge>}  $state
     */
    private function sameDependencyFingerprint(IsmsProject $project, array $rows, array $state, mixed $reviewed): bool
    {
        return is_string($reviewed)
            && hash_equals($reviewed, $this->stateFingerprint->makeDependencies($project, $rows, $state['processes'], $state['assets'], $state['edges']));
    }

    /**
     * @param  array{
     *     edges: Collection<int, DependencyEdge>,
     *     rows: list<array{values: array<string, string|bool|null>, source: DependencyNode, target: DependencyNode, existing: DependencyEdge|null}>
     * }  $state
     */
    private function assertFinalDependencyGraphIsAcyclic(array $state): void
    {
        $activeEdges = [];
        foreach ($state['edges'] as $edge) {
            if ($edge->is_active) {
                $activeEdges[$this->edgePairKey($edge)] = $this->edgePair($edge);
            }
        }
        foreach ($state['rows'] as $row) {
            $pairKey = $row['source']->identity().'>'.$row['target']->identity();
            if ($row['values']['active']) {
                $activeEdges[$pairKey] = ['source' => $row['source']->identity(), 'target' => $row['target']->identity()];
            } else {
                unset($activeEdges[$pairKey]);
            }
        }

        $this->cycleDetector->assertAcyclic([], array_values($activeEdges));
    }

    /**
     * @param  list<array{values: array<string, string|bool|null>, source: DependencyNode, target: DependencyNode, existing: DependencyEdge|null}>  $rows
     */
    private function applyDependencies(IsmsProject $project, array $rows, User $actor): void
    {
        foreach ($rows as $row) {
            if ($row['existing'] instanceof DependencyEdge
                && $row['existing']->importance->value === $row['values']['importance']
                && $row['existing']->reason === $row['values']['reason']
                && $row['existing']->is_active === $row['values']['active']) {
                continue;
            }
            $this->dependencies->upsertFromImport(
                $project,
                $row['source'],
                $row['target'],
                [
                    'importance' => $row['values']['importance'],
                    'reason' => $row['values']['reason'],
                    'active' => $row['values']['active'],
                ],
                $row['existing'],
                $actor,
            );
        }
    }

    /** @return array{source: string, target: string} */
    private function edgePair(DependencyEdge $edge): array
    {
        return [
            'source' => $edge->source_process_id !== null ? 'process:'.$edge->source_process_id : 'asset:'.$edge->source_asset_id,
            'target' => $edge->target_process_id !== null ? 'process:'.$edge->target_process_id : 'asset:'.$edge->target_asset_id,
        ];
    }

    private function edgePairKey(DependencyEdge $edge): string
    {
        $pair = $this->edgePair($edge);

        return $pair['source'].'>'.$pair['target'];
    }

    /**
     * @param  list<array<string, string|bool|null>>  $rows
     * @return Collection<string, BusinessProcess|Asset>
     */
    private function lockedExisting(IsmsProject $project, RegisterImportKind $kind, array $rows): Collection
    {
        $model = $kind === RegisterImportKind::Processes ? BusinessProcess::class : Asset::class;

        /** @var Collection<string, BusinessProcess|Asset> $existing */
        $existing = $model::query()
            ->where('project_id', $project->id)
            ->whereIn('key', array_column($rows, 'key'))
            ->lockForUpdate()
            ->get()
            ->keyBy('key');

        return $existing;
    }

    /**
     * @param  list<array<string, string|bool|null>>  $rows
     * @param  Collection<string, BusinessProcess|Asset>  $existing
     * @return array{new: int, changed: int, unchanged: int, invalid: int}
     */
    private function counts(RegisterImportKind $kind, array $rows, Collection $existing): array
    {
        $counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'invalid' => 0];
        $fields = $kind === RegisterImportKind::Processes
            ? ['name', 'description', 'owner_name', 'owner_email']
            : ['name', 'type', 'description', 'owner_name', 'owner_email'];
        foreach ($rows as $row) {
            $record = $existing->get($row['key']);
            if (! $record instanceof Model) {
                $category = 'new';
            } elseif ($this->changed($record, $row, $fields)) {
                $category = 'changed';
            } else {
                $category = 'unchanged';
            }
            $counts[$category]++;
        }

        return $counts;
    }

    /**
     * @param  array{new: int, changed: int, unchanged: int, invalid: int}  $actual
     */
    private function sameCounts(array $actual, mixed $reviewed): bool
    {
        if (! is_array($reviewed)) {
            return false;
        }

        ksort($actual);
        ksort($reviewed);

        return $actual === $reviewed;
    }

    /**
     * @param  list<array<string, string|bool|null>>  $rows
     * @param  Collection<string, BusinessProcess|Asset>  $existing
     */
    private function sameFingerprint(RegisterImportKind $kind, array $rows, Collection $existing, mixed $reviewed): bool
    {
        return is_string($reviewed)
            && hash_equals($reviewed, $this->stateFingerprint->make($kind, $rows, $existing));
    }

    /**
     * @param  array<string, string|bool|null>  $row
     * @param  list<string>  $fields
     */
    private function changed(Model $record, array $row, array $fields): bool
    {
        foreach ($fields as $field) {
            $current = $record->getAttribute($field);
            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }
            if ($current !== $row[$field]) {
                return true;
            }
        }

        return (bool) $record->getAttribute('is_active') !== $row['active'];
    }

    /**
     * @param  list<array<string, string|bool|null>>  $rows
     * @param  Collection<string, BusinessProcess|Asset>  $existing
     */
    private function apply(IsmsProject $project, RegisterImportKind $kind, array $rows, Collection $existing, User $actor): void
    {
        foreach ($rows as $row) {
            $record = $existing->get($row['key']);
            if (! $record instanceof Model) {
                $kind === RegisterImportKind::Processes
                    ? $this->processes->create($project, $row, $actor, false)
                    : $this->assets->create($project, $row, $actor, false);

                continue;
            }

            $attributes = $row;
            unset($attributes['key'], $attributes['active']);
            if ($record instanceof BusinessProcess) {
                $updated = $this->processes->update($record, $attributes, $actor, $record->updated_at->toIso8601String(), false);
                $this->processes->changeStatus($updated, (bool) $row['active'], $actor, false);
            } elseif ($record instanceof Asset) {
                $updated = $this->assets->update($record, $attributes, $actor, $record->updated_at->toIso8601String(), false);
                $this->assets->changeStatus($updated, (bool) $row['active'], $actor, false);
            }
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['import' => ['Der Import kann nicht bestätigt werden.']]);
    }
}
