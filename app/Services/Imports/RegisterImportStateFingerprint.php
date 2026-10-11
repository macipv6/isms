<?php

namespace App\Services\Imports;

use App\Enums\RegisterImportKind;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;

class RegisterImportStateFingerprint
{
    /** @param list<array<string, string|bool|null>> $rows */
    public function makeProtectionNeeds(IsmsProject $project, array $rows, bool $lock = false): string
    {
        $processQuery = BusinessProcess::query()->where('project_id', $project->id)->orderBy('id');
        $assetQuery = Asset::query()->where('project_id', $project->id)->orderBy('id');
        $needQuery = ProtectionNeed::query()->where('project_id', $project->id)->orderBy('id');
        $edgeQuery = DependencyEdge::query()->where('project_id', $project->id)->where('is_active', true)->orderBy('id');
        if ($lock) {
            $processQuery->lockForUpdate();
            $assetQuery->lockForUpdate();
            $needQuery->lockForUpdate();
            $edgeQuery->lockForUpdate();
        }
        $processes = $processQuery->get();
        $assets = $assetQuery->get();
        $processesByKey = $processes->keyBy('key');
        $assetsByKey = $assets->keyBy('key');
        $needs = $needQuery->get();
        $edges = $edgeQuery->get();
        $targets = [];
        foreach ($rows as $row) {
            $records = $row['object_type'] === 'process' ? $processesByKey : $assetsByKey;
            $record = $records->get($row['object_key']);
            $targets[$row['object_type'].'|'.$row['object_key']] = $record ? [
                'id' => $record->id, 'active' => $record->is_active, 'updated_at' => $record->updated_at->toIso8601String(),
            ] : null;
        }
        ksort($targets);

        $direct = $needs->mapWithKeys(fn (ProtectionNeed $need): array => [$need->id => [
            'process_id' => $need->business_process_id,
            'asset_id' => $need->asset_id,
            'objective' => $need->objective->value,
            'level' => $need->direct_level?->value,
            'rationale' => $need->direct_rationale,
            'version' => $need->lock_version,
            'status' => $need->status->value,
        ]])->all();
        $graph = [
            'processes' => $processes->mapWithKeys(fn (BusinessProcess $process): array => [$process->id => $process->is_active])->all(),
            'assets' => $assets->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->is_active])->all(),
            'edges' => $edges->mapWithKeys(fn (DependencyEdge $edge): array => [$edge->id => [
                $edge->source_process_id, $edge->source_asset_id, $edge->target_process_id, $edge->target_asset_id,
                $edge->importance->value, $edge->reason, $edge->updated_at->toIso8601String(),
            ]])->all(),
        ];

        return hash_hmac('sha256', json_encode([
            'kind' => RegisterImportKind::ProtectionNeeds->value,
            'project' => [$project->id, $project->organization_id, $project->status->value, $project->organization?->is_active],
            'targets' => $targets,
            'direct' => $direct,
            'graph' => $graph,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * @template TKey of array-key
     * @template TModel of BusinessProcess|Asset
     *
     * @param  list<array<string, string|bool|null>>  $rows
     * @param  Collection<TKey, TModel>  $existing
     *
     * @throws JsonException
     */
    public function make(RegisterImportKind $kind, array $rows, Collection $existing): string
    {
        $statesByKey = [];
        foreach ($rows as $row) {
            $key = $row['key'];
            $record = $existing->get($key);
            $state = $record instanceof Model
                ? $this->existingState($kind, $record)
                : ['exists' => false];
            ksort($state);
            $statesByKey[$key] = $state;
        }
        ksort($statesByKey);

        return hash_hmac(
            'sha256',
            json_encode(['kind' => $kind->value, 'states' => $statesByKey], JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }

    /**
     * @param  list<array<string, string|bool|null>>  $rows
     * @param  Collection<string, BusinessProcess>  $processes
     * @param  Collection<string, Asset>  $assets
     * @param  Collection<int, DependencyEdge>  $edges
     *
     * @throws JsonException
     */
    public function makeDependencies(IsmsProject $project, array $rows, Collection $processes, Collection $assets, Collection $edges): string
    {
        $processesById = $processes->keyBy('id');
        $assetsById = $assets->keyBy('id');
        $endpoints = [];
        foreach ($rows as $row) {
            foreach (['source', 'target'] as $side) {
                $type = $row[$side.'_type'];
                $key = $row[$side.'_key'];
                $record = $type === 'process' ? $processes->get($key) : $assets->get($key);
                $identity = $record instanceof Model ? $type.':'.$record->getKey() : $type.':missing:'.$key;
                $endpoints[$identity] = $record instanceof Model
                    ? $this->dependencyEndpointState($record)
                    : ['exists' => false];
            }
        }

        $edgeStates = [];
        foreach ($edges as $edge) {
            foreach ([
                ['process', $edge->source_process_id],
                ['asset', $edge->source_asset_id],
                ['process', $edge->target_process_id],
                ['asset', $edge->target_asset_id],
            ] as [$type, $id]) {
                if ($id === null) {
                    continue;
                }
                $record = $type === 'process' ? $processesById->get($id) : $assetsById->get($id);
                $endpoints[$type.':'.$id] = $record instanceof Model
                    ? $this->dependencyEndpointState($record)
                    : ['exists' => false];
            }
            $edgeStates[$edge->id] = [
                'source_process_id' => $edge->source_process_id,
                'source_asset_id' => $edge->source_asset_id,
                'target_process_id' => $edge->target_process_id,
                'target_asset_id' => $edge->target_asset_id,
                'importance' => $edge->importance->value,
                'reason' => $edge->reason,
                'active' => $edge->is_active,
            ];
        }
        ksort($endpoints);
        ksort($edgeStates);

        $projectState = [
            'id' => $project->id,
            'organization_id' => $project->organization_id,
            'status' => $project->status->value,
            'organization_type' => $project->organization?->organization_type,
            'organization_active' => (bool) $project->organization?->is_active,
        ];
        ksort($projectState);

        return hash_hmac(
            'sha256',
            json_encode([
                'kind' => RegisterImportKind::Dependencies->value,
                'project' => $projectState,
                'endpoints' => $endpoints,
                'edges' => $edgeStates,
            ], JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }

    /** @return array{exists: true, id: mixed, key: mixed, active: bool} */
    private function dependencyEndpointState(Model $record): array
    {
        return [
            'exists' => true,
            'id' => $record->getKey(),
            'key' => $record->getAttribute('key'),
            'active' => (bool) $record->getAttribute('is_active'),
        ];
    }

    /** @return array<string, string|bool|null> */
    private function existingState(RegisterImportKind $kind, Model $record): array
    {
        $fields = $kind === RegisterImportKind::Processes
            ? ['name', 'description', 'owner_name', 'owner_email']
            : ['name', 'type', 'description', 'owner_name', 'owner_email'];
        $state = ['exists' => true];
        foreach ($fields as $field) {
            $value = $record->getAttribute($field);
            $state[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        $state['active'] = (bool) $record->getAttribute('is_active');

        return $state;
    }
}
