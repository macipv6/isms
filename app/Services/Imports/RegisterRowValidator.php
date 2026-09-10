<?php

namespace App\Services\Imports;

use App\Data\Imports\RegisterCsvRow;
use App\Enums\AssetType;
use App\Enums\DependencyImportance;
use App\Enums\DependencyNodeType;
use App\Enums\RegisterImportKind;
use App\Services\Registers\RegisterKey;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RegisterRowValidator
{
    /** @param array<string, mixed> $row */
    public function validate(RegisterImportKind $kind, array $row, int $line): RegisterCsvRow
    {
        $values = [];
        foreach ($this->headers($kind) as $field) {
            $value = $row[$field] ?? null;
            $values[$field] = is_string($value) ? trim($value) : $value;
            if (is_string($values[$field]) && $values[$field] !== '' && in_array($values[$field][0], ['=', '+', '-', '@'], true)) {
                $this->reject($line, $field);
            }
        }

        foreach ($this->optionalFields($kind) as $field) {
            if ($values[$field] === '') {
                $values[$field] = null;
            }
        }

        try {
            $values['key'] = isset($values['key']) ? RegisterKey::normalize((string) $values['key']) : null;
            foreach (['source_key', 'target_key'] as $field) {
                if (isset($values[$field])) {
                    $values[$field] = RegisterKey::normalize((string) $values[$field]);
                }
            }
        } catch (ValidationException $exception) {
            $this->rethrow($exception, $line);
        }

        foreach (['active'] as $field) {
            $value = strtolower((string) $values[$field]);
            if (! in_array($value, ['true', 'false'], true)) {
                $this->reject($line, $field);
            }
            $values[$field] = $value === 'true';
        }

        if (isset($values['type']) && AssetType::tryFrom((string) $values['type']) === null) {
            $this->reject($line, 'type');
        }
        if (isset($values['source_type']) && DependencyNodeType::tryFrom((string) $values['source_type']) === null) {
            $this->reject($line, 'source_type');
        }
        if (isset($values['target_type']) && DependencyNodeType::tryFrom((string) $values['target_type']) === null) {
            $this->reject($line, 'target_type');
        }
        if (isset($values['importance']) && DependencyImportance::tryFrom((string) $values['importance']) === null) {
            $this->reject($line, 'importance');
        }
        if ($kind === RegisterImportKind::Dependencies && $values['source_type'] === 'asset' && $values['target_type'] === 'process') {
            $this->reject($line, 'target_type');
        }
        if ($kind === RegisterImportKind::Dependencies && $values['source_type'] === $values['target_type'] && $values['source_key'] === $values['target_key']) {
            $this->reject($line, 'target_key');
        }

        try {
            Validator::make($values, $this->rules($kind))->validate();
        } catch (ValidationException $exception) {
            $this->rethrow($exception, $line);
        }

        /** @var array<string, string|bool|null> $values */
        return new RegisterCsvRow($line, $values);
    }

    /** @return list<string> */
    public function headers(RegisterImportKind $kind): array
    {
        return match ($kind) {
            RegisterImportKind::Processes => ['key', 'name', 'description', 'owner_name', 'owner_email', 'active'],
            RegisterImportKind::Assets => ['key', 'name', 'type', 'description', 'owner_name', 'owner_email', 'active'],
            RegisterImportKind::Dependencies => ['source_type', 'source_key', 'target_type', 'target_key', 'importance', 'reason', 'active'],
        };
    }

    /** @return list<string> */
    private function optionalFields(RegisterImportKind $kind): array
    {
        return $kind === RegisterImportKind::Dependencies ? ['reason'] : ['description', 'owner_name', 'owner_email'];
    }

    /** @return array<string, list<string>> */
    private function rules(RegisterImportKind $kind): array
    {
        $common = ['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:4000'], 'owner_name' => ['nullable', 'string', 'max:160'], 'owner_email' => ['nullable', 'email:rfc', 'max:254']];

        return match ($kind) {
            RegisterImportKind::Processes => $common,
            RegisterImportKind::Assets => array_merge($common, ['type' => ['required', 'string']]),
            RegisterImportKind::Dependencies => ['reason' => ['nullable', 'string', 'max:1000']],
        };
    }

    private function rethrow(ValidationException $exception, int $line): never
    {
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $errors['rows.'.$line.'.'.$field] = $messages;
        }
        throw ValidationException::withMessages($errors);
    }

    private function reject(int $line, string $field): never
    {
        throw ValidationException::withMessages(['rows.'.$line.'.'.$field => ['Die CSV-Zeile ist nicht zulässig.']]);
    }
}
