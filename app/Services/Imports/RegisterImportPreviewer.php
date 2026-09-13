<?php

namespace App\Services\Imports;

use App\Data\Imports\ParsedRegisterCsv;
use App\Data\Imports\RegisterImportPreview;
use App\Enums\ProjectStatus;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Enums\UserRole;
use App\Exceptions\RegisterCsvValidationException;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Dependencies\DependencyCycleDetector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterImportPreviewer
{
    private const MAX_PREVIEW_ROWS = 200;

    public function __construct(
        private readonly RegisterCsvReader $reader,
        private readonly DependencyCycleDetector $cycleDetector,
        private readonly AuditLogger $audit,
    ) {}

    public function preview(IsmsProject $project, RegisterImportKind $kind, UploadedFile $file, User $actor): RegisterImportBatch
    {
        $parseInvalidCount = 0;
        try {
            $parsed = $this->reader->read($file, $kind);
            $parseErrors = null;
            $sha256 = $parsed->sha256;
        } catch (RegisterCsvValidationException $exception) {
            $parsed = $exception->parsed;
            $parseErrors = $exception->errors();
            $parseInvalidCount = $exception->invalidRowCount;
            $sha256 = $parsed->sha256;
        } catch (ValidationException $exception) {
            $parsed = null;
            $parseErrors = $exception->errors();
            $sha256 = hash_file('sha256', $file->getPathname()) ?: hash('sha256', '');
        }

        return DB::transaction(function () use ($project, $kind, $actor, $parsed, $parseErrors, $parseInvalidCount, $sha256): RegisterImportBatch {
            $lockedProject = $this->lockedWritableProject($project, $actor);
            $preview = $parsed instanceof ParsedRegisterCsv
                ? $this->withParseErrors($this->categorize($lockedProject, $parsed), $parseErrors ?? [], $parseInvalidCount)
                : $this->rejectedPreview($parseErrors);

            $batch = RegisterImportBatch::query()->create([
                'project_id' => $lockedProject->id,
                'kind' => $kind,
                'created_by' => $actor->id,
                'sha256' => $sha256,
                'payload' => $preview->payload,
                'summary' => $preview->summary,
                'status' => $preview->status,
                'expires_at' => now('UTC')->addMinutes(30),
                'applied_at' => null,
            ]);
            $this->audit->record(
                $preview->status === RegisterImportStatus::Pending ? 'register_import.previewed' : 'register_import.rejected',
                $actor,
                [
                    'project_id' => $lockedProject->id,
                    'import_batch_id' => $batch->id,
                    'import_kind' => $kind->value,
                    'row_counts' => $preview->summary['counts'],
                ],
                $lockedProject->organization_id,
            );

            return $batch->refresh();
        });
    }

    private function categorize(IsmsProject $project, ParsedRegisterCsv $parsed): RegisterImportPreview
    {
        return match ($parsed->kind) {
            RegisterImportKind::Processes => $this->categorizeRecords($parsed, BusinessProcess::query()->where('project_id', $project->id)->get()->keyBy('key'), ['name', 'description', 'owner_name', 'owner_email']),
            RegisterImportKind::Assets => $this->categorizeRecords($parsed, Asset::query()->where('project_id', $project->id)->get()->keyBy('key'), ['name', 'type', 'description', 'owner_name', 'owner_email']),
            RegisterImportKind::Dependencies => $this->categorizeDependencies($project, $parsed),
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int|string, TModel>  $existing
     * @param  list<string>  $fields
     */
    private function categorizeRecords(ParsedRegisterCsv $parsed, $existing, array $fields): RegisterImportPreview
    {
        $payload = [];
        $rows = [];
        foreach ($parsed->rows as $row) {
            $payload[] = $row->values;
            $record = $existing->get($row->values['key']);
            $category = $record === null ? 'new' : ($this->recordChanged($record, $row->values, $fields) ? 'changed' : 'unchanged');
            $rows[] = ['line' => $row->line, 'category' => $category, 'code' => null, 'values' => $row->values];
        }

        return $this->buildPreview($payload, $rows);
    }

    /**
     * @param  array<string, string|bool|null>  $values
     * @param  list<string>  $fields
     */
    private function recordChanged(Model $record, array $values, array $fields): bool
    {
        foreach ($fields as $field) {
            $current = $record->getAttribute($field);
            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }
            if ($current !== $values[$field]) {
                return true;
            }
        }

        return (bool) $record->getAttribute('is_active') !== $values['active'];
    }

    private function categorizeDependencies(IsmsProject $project, ParsedRegisterCsv $parsed): RegisterImportPreview
    {
        $processes = BusinessProcess::query()->where('project_id', $project->id)->get()->keyBy('key');
        $assets = Asset::query()->where('project_id', $project->id)->get()->keyBy('key');
        $edges = DependencyEdge::query()->where('project_id', $project->id)->get();
        $existingByPair = [];
        $activeEdges = [];
        $activeNodes = [];
        foreach ($processes as $process) {
            if ($process->is_active) {
                $activeNodes['process:'.$process->id] = true;
            }
        }
        foreach ($assets as $asset) {
            if ($asset->is_active) {
                $activeNodes['asset:'.$asset->id] = true;
            }
        }
        foreach ($edges as $edge) {
            $pair = $this->edgePair($edge);
            $existingByPair[$pair['source'].'>'.$pair['target']] = $edge;
            if ($edge->is_active && isset($activeNodes[$pair['source']], $activeNodes[$pair['target']])) {
                $activeEdges[$pair['source'].'>'.$pair['target']] = $pair;
            }
        }

        $payload = [];
        $rows = [];
        $candidates = [];
        foreach ($parsed->rows as $row) {
            $payload[] = $row->values;
            [$source, $sourceCode] = $this->resolveNode($row->values['source_type'], $row->values['source_key'], $processes, $assets);
            [$target, $targetCode] = $this->resolveNode($row->values['target_type'], $row->values['target_key'], $processes, $assets);
            $code = $sourceCode ?? $targetCode;
            if ($code !== null) {
                $rows[] = ['line' => $row->line, 'category' => 'invalid', 'code' => $code, 'values' => $row->values];

                continue;
            }

            $pairKey = $source.'>'.$target;
            $existing = $existingByPair[$pairKey] ?? null;
            $category = $existing === null ? 'new' : ($this->dependencyChanged($existing, $row->values) ? 'changed' : 'unchanged');
            $rows[] = ['line' => $row->line, 'category' => $category, 'code' => null, 'values' => $row->values, 'pair' => $pairKey];
            $candidates[$pairKey] = ['source' => $source, 'target' => $target, 'active' => $row->values['active']];
        }

        foreach ($candidates as $pairKey => $candidate) {
            if ($candidate['active']) {
                $activeEdges[$pairKey] = ['source' => $candidate['source'], 'target' => $candidate['target']];
            } else {
                unset($activeEdges[$pairKey]);
            }
        }
        foreach ($rows as &$row) {
            if (isset($row['pair'], $candidates[$row['pair']]) && $candidates[$row['pair']]['active']) {
                $candidate = $candidates[$row['pair']];
                if ($this->cycleDetector->edgeParticipatesInCycle(array_values($activeEdges), ['source' => $candidate['source'], 'target' => $candidate['target']])) {
                    $row['category'] = 'invalid';
                    $row['code'] = 'cycle';
                }
            }
        }
        unset($row);
        foreach ($rows as &$row) {
            unset($row['pair']);
        }
        unset($row);

        return $this->buildPreview($payload, $rows);
    }

    /** @param array<string, list<string>> $errors */
    private function withParseErrors(RegisterImportPreview $preview, array $errors, int $invalidRowCount): RegisterImportPreview
    {
        if ($invalidRowCount === 0) {
            return $preview;
        }

        $rows = [...$preview->summary['rows'], ...$this->errorRows($errors)];
        usort($rows, static fn (array $left, array $right): int => ($left['line'] ?? PHP_INT_MAX) <=> ($right['line'] ?? PHP_INT_MAX));
        $counts = $preview->summary['counts'];
        $counts['invalid'] += $invalidRowCount;

        return new RegisterImportPreview(
            $preview->payload,
            ['counts' => $counts, 'rows' => array_slice($rows, 0, self::MAX_PREVIEW_ROWS)],
            RegisterImportStatus::Rejected,
        );
    }

    /**
     * @param  Collection<string, BusinessProcess>  $processes
     * @param  Collection<string, Asset>  $assets
     * @return array{string|null, string|null}
     */
    private function resolveNode(string $type, string $key, $processes, $assets): array
    {
        $record = $type === 'process' ? $processes->get($key) : $assets->get($key);
        if ($record === null) {
            return [null, 'unknown_endpoint'];
        }
        if (! $record->is_active) {
            return [null, 'inactive_endpoint'];
        }

        return [$type.':'.$record->id, null];
    }

    /** @return array{source: string, target: string} */
    private function edgePair(DependencyEdge $edge): array
    {
        return [
            'source' => $edge->source_process_id !== null ? 'process:'.$edge->source_process_id : 'asset:'.$edge->source_asset_id,
            'target' => $edge->target_process_id !== null ? 'process:'.$edge->target_process_id : 'asset:'.$edge->target_asset_id,
        ];
    }

    /** @param array<string, string|bool|null> $values */
    private function dependencyChanged(DependencyEdge $edge, array $values): bool
    {
        return $edge->importance->value !== $values['importance']
            || $edge->reason !== $values['reason']
            || $edge->is_active !== $values['active'];
    }

    /**
     * @param  list<array<string, string|bool|null>>  $payload
     * @param  list<array<string, mixed>>  $rows
     */
    private function buildPreview(array $payload, array $rows): RegisterImportPreview
    {
        $counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'invalid' => 0];
        foreach ($rows as $row) {
            $counts[$row['category']]++;
        }

        return new RegisterImportPreview(
            $payload,
            ['counts' => $counts, 'rows' => array_slice($rows, 0, self::MAX_PREVIEW_ROWS)],
            $counts['invalid'] === 0 ? RegisterImportStatus::Pending : RegisterImportStatus::Rejected,
        );
    }

    /** @param array<string, list<string>> $errors */
    private function rejectedPreview(array $errors): RegisterImportPreview
    {
        return $this->buildPreview([], $this->errorRows($errors));
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array{line: int|null, field: string, category: string, code: string}>
     */
    private function errorRows(array $errors): array
    {
        $rows = [];
        foreach (array_keys($errors) as $coordinate) {
            preg_match('/^rows\.(\d+)\.([^.]*)$/', $coordinate, $matches);
            $rows[] = [
                'line' => isset($matches[1]) ? (int) $matches[1] : null,
                'field' => $matches[2] ?? 'file',
                'category' => 'invalid',
                'code' => 'invalid_row',
            ];
        }

        return $rows;
    }

    private function lockedWritableProject(IsmsProject $project, User $actor): IsmsProject
    {
        $actor->loadMissing('organization');
        if (! $actor->is_active || $actor->organization?->organization_type !== 'internal' || ! in_array($actor->role, [UserRole::Admin, UserRole::Consultant], true)) {
            throw ValidationException::withMessages(['import' => ['Die Aktion ist nicht zulässig.']]);
        }
        $locked = IsmsProject::query()->with('organization')->whereKey($project->id)->lockForUpdate()->first();
        if (! $locked instanceof IsmsProject || $locked->organization?->organization_type !== 'customer' || ! $locked->organization->is_active || ! in_array($locked->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            throw ValidationException::withMessages(['import' => ['Das Projekt ist nicht beschreibbar.']]);
        }

        return $locked;
    }
}
