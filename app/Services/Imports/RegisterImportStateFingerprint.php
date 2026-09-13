<?php

namespace App\Services\Imports;

use App\Enums\RegisterImportKind;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;

class RegisterImportStateFingerprint
{
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
    public function makeDependencies(array $rows, Collection $processes, Collection $assets, Collection $edges): string
    {
        $endpoints = [];
        foreach ($rows as $row) {
            foreach (['source', 'target'] as $side) {
                $type = $row[$side.'_type'];
                $key = $row[$side.'_key'];
                $record = $type === 'process' ? $processes->get($key) : $assets->get($key);
                $endpoints[$type.':'.$key] = $record instanceof Model
                    ? ['exists' => true, 'id' => $record->getKey(), 'active' => (bool) $record->getAttribute('is_active')]
                    : ['exists' => false];
            }
        }
        ksort($endpoints);

        $edgeStates = [];
        foreach ($edges as $edge) {
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
        ksort($edgeStates);

        return hash_hmac(
            'sha256',
            json_encode(['kind' => RegisterImportKind::Dependencies->value, 'endpoints' => $endpoints, 'edges' => $edgeStates], JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
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
