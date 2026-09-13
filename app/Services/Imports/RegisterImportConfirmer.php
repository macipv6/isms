<?php

namespace App\Services\Imports;

use App\Enums\ProjectStatus;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
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
                $existing = $this->lockedExisting($project, $lockedBatch->kind, $rows);
                $counts = $this->counts($lockedBatch->kind, $rows, $existing);
                if (! $this->sameCounts($counts, $lockedBatch->summary['counts'] ?? null)
                    || ! $this->sameFingerprint($lockedBatch->kind, $rows, $existing, $lockedBatch->summary['state_fingerprint'] ?? null)) {
                    $this->reject();
                }

                $this->apply($project, $lockedBatch->kind, $rows, $existing, $actor);
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
            || $batch->expires_at->getTimestamp() <= now('UTC')->getTimestamp()
            || ! in_array($batch->kind, [RegisterImportKind::Processes, RegisterImportKind::Assets], true)) {
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
        $keys = [];
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
            if ($row !== $canonicalPayloadRow || ! isset($row['key']) || ! is_string($row['key']) || isset($keys[$row['key']])) {
                $this->reject();
            }
            $keys[$row['key']] = true;
            $rows[] = $row;
        }

        return $rows;
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
