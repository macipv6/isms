<?php

namespace App\Services\Imports;

use App\Enums\RegisterImportKind;
use App\Models\Asset;
use App\Models\BusinessProcess;
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
