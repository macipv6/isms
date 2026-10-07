<?php

namespace App\Services\Imports;

use App\Data\Imports\ParsedRegisterCsv;
use App\Data\Imports\RegisterImportPreview;
use App\Enums\ProjectStatus;
use App\Enums\ProtectionLevel;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Enums\UserRole;
use App\Exceptions\RegisterCsvValidationException;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
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
        private readonly RegisterImportStateFingerprint $stateFingerprint,
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
                ($kind === RegisterImportKind::ProtectionNeeds ? 'protection_needs_import.' : 'register_import.')
                    .($preview->status === RegisterImportStatus::Pending ? 'previewed' : 'rejected'),
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
            RegisterImportKind::ProtectionNeeds => $this->categorizeProtectionNeeds($project, $parsed),
        };
    }

    private function categorizeProtectionNeeds(IsmsProject $project, ParsedRegisterCsv $parsed): RegisterImportPreview
    {
        $keys = array_map(fn ($row): string => $row->values['object_key'], $parsed->rows);
        $processes = BusinessProcess::query()->where('project_id', $project->id)->whereIn('key', $keys)->get()->keyBy('key');
        $assets = Asset::query()->where('project_id', $project->id)->whereIn('key', $keys)->get()->keyBy('key');
        $needs = collect(ProtectionNeed::query()->where('project_id', $project->id)->with(['downgradeRequests', 'sources'])->get()
            ->groupBy(fn (ProtectionNeed $need): string => ($need->business_process_id ? 'process:'.$need->business_process_id : 'asset:'.$need->asset_id))->all());
        $projectedInheritance = $this->projectedInheritance($project, $parsed, $processes, $assets, $needs);
        $payload = [];
        $rows = [];
        foreach ($parsed->rows as $row) {
            $values = $row->values;
            $payload[] = $values;
            $record = ($values['object_type'] === 'process' ? $processes : $assets)->get($values['object_key']);
            if ($record === null || ! $record->is_active) {
                $rows[] = ['line' => $row->line, 'category' => 'invalid', 'code' => $record === null ? 'unknown_endpoint' : 'inactive_endpoint', 'values' => $values];

                continue;
            }
            $current = $needs->get($values['object_type'].':'.$record->id, collect())->keyBy(fn (ProtectionNeed $need): string => $need->objective->value);
            $isNew = $current->isEmpty() || $current->every(fn (ProtectionNeed $need): bool => $need->direct_level === null);
            $category = $isNew ? 'new' : 'unchanged';
            $downgrade = false;
            foreach (['confidentiality', 'integrity', 'availability'] as $objective) {
                $need = $current->get($objective);
                if ($need === null || $need->direct_level?->value !== $values[$objective] || $need->direct_rationale !== $values[$objective.'_rationale']) {
                    $category = $isNew ? 'new' : 'changed';
                }
                $projected = $projectedInheritance[$values['object_type'].':'.$record->id][$objective] ?? null;
                $inherited = $projected['level'] ?? null;
                if ($inherited && $inherited->rank() > ProtectionLevel::from($values[$objective])->rank()) {
                    $approved = $need?->downgradeRequests->contains(fn ($request): bool => $request->status->value === 'approved' && $request->inherited_level->value === $inherited->value && $request->requested_level->value === $values[$objective]) ?? false;
                    $directChanged = $need?->direct_level?->value !== $values[$objective] || $need?->direct_rationale !== $values[$objective.'_rationale'];
                    $downgrade = $downgrade || $directChanged || ! $approved || ! $this->sameProjectedSources($need, $projected['sources']);
                }
            }
            $rows[] = ['line' => $row->line, 'category' => $category, 'code' => null, 'values' => $values, 'requires_downgrade_approval' => $downgrade];
        }
        $preview = $this->buildPreview($payload, $rows);

        return new RegisterImportPreview($preview->payload, [...$preview->summary, 'state_fingerprint' => $this->stateFingerprint->makeProtectionNeeds($project, $payload)], $preview->status);
    }

    /**
     * @param  Collection<string, BusinessProcess>  $processes
     * @param  Collection<string, Asset>  $assets
     * @param  Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, ProtectionNeed>>  $needs
     * @return array<string, array<string, array{level: ProtectionLevel, sources: list<array{origin: string, level: string, edge: string|null}>}>>
     */
    private function projectedInheritance(IsmsProject $project, ParsedRegisterCsv $parsed, Collection $processes, Collection $assets, Collection $needs): array
    {
        $nodes = [];
        foreach (BusinessProcess::query()->where('project_id', $project->id)->where('is_active', true)->pluck('id') as $id) {
            $nodes['process:'.$id] = true;
        }
        foreach (Asset::query()->where('project_id', $project->id)->where('is_active', true)->pluck('id') as $id) {
            $nodes['asset:'.$id] = true;
        }
        $own = [];
        foreach ($parsed->rows as $row) {
            $record = ($row->values['object_type'] === 'process' ? $processes : $assets)->get($row->values['object_key']);
            if ($record && $record->is_active) {
                foreach (['confidentiality', 'integrity', 'availability'] as $objective) {
                    $own[$row->values['object_type'].':'.$record->id][$objective] = [
                        'level' => ProtectionLevel::from($row->values[$objective]),
                        'rationale' => $row->values[$objective.'_rationale'],
                    ];
                }
            }
        }
        $out = [];
        $indegree = array_fill_keys(array_keys($nodes), 0);
        foreach (DependencyEdge::query()->where('project_id', $project->id)->where('is_active', true)->get() as $edge) {
            $source = $edge->source_process_id ? 'process:'.$edge->source_process_id : 'asset:'.$edge->source_asset_id;
            $target = $edge->target_process_id ? 'process:'.$edge->target_process_id : 'asset:'.$edge->target_asset_id;
            if (isset($nodes[$source], $nodes[$target])) {
                $out[$source][] = [$target, $edge->id];
                $indegree[$target]++;
            }
        }
        $ready = array_keys(array_filter($indegree, fn (int $count): bool => $count === 0));
        $incoming = [];
        $projected = [];
        while ($ready !== []) {
            $node = array_pop($ready);
            $current = $needs->get($node, collect())->keyBy(fn (ProtectionNeed $need): string => $need->objective->value);
            foreach (['confidentiality', 'integrity', 'availability'] as $objective) {
                $need = $current->get($objective);
                $direct = $own[$node][$objective]['level'] ?? $need?->direct_level;
                $rationale = $own[$node][$objective]['rationale'] ?? $need?->direct_rationale;
                $candidates = $incoming[$node][$objective] ?? [];
                $upstream = null;
                foreach ($candidates as $candidate) {
                    if ($upstream === null || $candidate['level']->rank() > $upstream->rank()) {
                        $upstream = $candidate['level'];
                    }
                }
                $sources = [];
                foreach ($candidates as $candidate) {
                    if ($candidate['level'] === $upstream && (! isset($sources[$candidate['origin']]) || $candidate['edge'] < $sources[$candidate['origin']]['edge'])) {
                        $sources[$candidate['origin']] = $candidate;
                    }
                }
                ksort($sources);
                $sourceStates = array_values(array_map(fn (array $source): array => [
                    'origin' => $source['origin'], 'level' => $source['level']->value, 'edge' => $source['edge'],
                ], $sources));
                if ($upstream !== null) {
                    $projected[$node][$objective] = ['level' => $upstream, 'sources' => $sourceStates];
                }
                $changed = array_key_exists($node, $own) && ($need?->direct_level !== $direct || $need?->direct_rationale !== $rationale);
                $approved = ! $changed && $this->sameProjectedSources($need, $sourceStates)
                    && ($need?->downgradeRequests->contains(fn ($request): bool => $request->status->value === 'approved' && $request->inherited_level === $upstream && $request->requested_level === $direct) ?? false);
                if ($upstream && (! $direct || ($upstream->rank() > $direct->rank() && ! $approved))) {
                    $effective = $upstream;
                } elseif ($direct) {
                    $effective = $direct;
                } else {
                    continue;
                }
                $origins = [];
                if ($direct === $effective) {
                    $origins[$node] = ['origin' => $node, 'level' => $effective, 'edge' => null];
                }
                if ($upstream === $effective) {
                    foreach ($sources as $source) {
                        $origins[$source['origin']] = $source;
                    }
                }
                foreach ($out[$node] ?? [] as [$target, $edgeId]) {
                    foreach ($origins as $origin) {
                        $incoming[$target][$objective][] = [...$origin, 'edge' => $origin['edge'] ?? $edgeId];
                    }
                }
            }
            foreach ($out[$node] ?? [] as [$target]) {
                if (--$indegree[$target] === 0) {
                    $ready[] = $target;
                }
            }
        }

        return $projected;
    }

    /** @param list<array{origin: string, level: string, edge: string|null}> $projected */
    private function sameProjectedSources(?ProtectionNeed $need, array $projected): bool
    {
        if ($need === null) {
            return $projected === [];
        }

        $current = $need->sources->map(fn ($source): array => [
            'origin' => $source->origin_process_id ? 'process:'.$source->origin_process_id : 'asset:'.$source->origin_asset_id,
            'level' => $source->origin_level->value,
            'edge' => $source->first_edge_id,
        ])->sortBy('origin')->values()->all();

        return $current === $projected;
    }

    /**
     * @template TModel of BusinessProcess|Asset
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

        $preview = $this->buildPreview($payload, $rows);

        return new RegisterImportPreview(
            $preview->payload,
            [...$preview->summary, 'state_fingerprint' => $this->stateFingerprint->make($parsed->kind, $payload, $existing)],
            $preview->status,
        );
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
        $processes = collect();
        BusinessProcess::query()->where('project_id', $project->id)->chunkById(500, function ($chunk) use ($processes): void {
            foreach ($chunk as $process) {
                $processes->put($process->key, $process);
            }
        });
        $assets = collect();
        Asset::query()->where('project_id', $project->id)->chunkById(500, function ($chunk) use ($assets): void {
            foreach ($chunk as $asset) {
                $assets->put($asset->key, $asset);
            }
        });
        $edges = collect();
        DependencyEdge::query()
            ->where('project_id', $project->id)
            ->oldest('created_at')
            ->oldest('id')
            ->chunk(500, function ($chunk) use ($edges): void {
                $edges->push(...$chunk);
            });
        $existingByPair = [];
        $activeEdges = [];
        foreach ($edges as $edge) {
            $pair = $this->edgePair($edge);
            $pairKey = $pair['source'].'>'.$pair['target'];
            if (! isset($existingByPair[$pairKey]) || $edge->is_active) {
                $existingByPair[$pairKey] = $edge;
            }
            if ($edge->is_active) {
                $activeEdges[$pairKey] = $pair;
            }
        }

        $payload = [];
        $rows = [];
        $candidates = [];
        foreach ($parsed->rows as $row) {
            $payload[] = $row->values;
            [$source, $sourceCode, $sourceActive] = $this->resolveNode($row->values['source_type'], $row->values['source_key'], $processes, $assets);
            [$target, $targetCode, $targetActive] = $this->resolveNode($row->values['target_type'], $row->values['target_key'], $processes, $assets);
            $code = $sourceCode ?? $targetCode;
            if ($code !== null) {
                $rows[] = ['line' => $row->line, 'category' => 'invalid', 'code' => $code, 'values' => $row->values];

                continue;
            }

            $pairKey = $source.'>'.$target;
            $existing = $existingByPair[$pairKey] ?? null;
            if ($this->requiresActiveEndpoints($existing, $row->values['active']) && (! $sourceActive || ! $targetActive)) {
                $rows[] = ['line' => $row->line, 'category' => 'invalid', 'code' => 'inactive_endpoint', 'values' => $row->values];

                continue;
            }
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
        $cycleEdges = $this->cycleDetector->cycleEdgeKeys(array_values($activeEdges));
        $candidateCycleFound = false;
        foreach ($rows as &$row) {
            if (isset($row['pair'], $candidates[$row['pair']], $cycleEdges[$row['pair']]) && $candidates[$row['pair']]['active']) {
                $row['category'] = 'invalid';
                $row['code'] = 'cycle';
                $candidateCycleFound = true;
            }
        }
        unset($row);
        if ($cycleEdges !== [] && ! $candidateCycleFound) {
            $rows[] = ['line' => null, 'field' => 'dependencies', 'category' => 'invalid', 'code' => 'cycle'];
        }
        foreach ($rows as &$row) {
            unset($row['pair']);
        }
        unset($row);

        $preview = $this->buildPreview($payload, $rows);

        return new RegisterImportPreview(
            $preview->payload,
            [...$preview->summary, 'state_fingerprint' => $this->stateFingerprint->makeDependencies($project, $payload, $processes, $assets, $edges)],
            $preview->status,
        );
    }

    /** @param array<string, list<string>> $errors */
    private function withParseErrors(RegisterImportPreview $preview, array $errors, int $invalidRowCount): RegisterImportPreview
    {
        if ($invalidRowCount === 0) {
            return $preview;
        }

        $rows = [...$this->errorRows($errors), ...$preview->summary['rows']];
        $counts = $preview->summary['counts'];
        $counts['invalid'] += $invalidRowCount;

        return new RegisterImportPreview(
            $preview->payload,
            ['counts' => $counts, 'rows' => $this->boundedRows($rows)],
            RegisterImportStatus::Rejected,
        );
    }

    /**
     * @param  Collection<string, BusinessProcess>  $processes
     * @param  Collection<string, Asset>  $assets
     * @return array{string|null, string|null, bool}
     */
    private function resolveNode(string $type, string $key, $processes, $assets): array
    {
        $record = $type === 'process' ? $processes->get($key) : $assets->get($key);
        if ($record === null) {
            return [null, 'unknown_endpoint', false];
        }

        return [$type.':'.$record->id, null, (bool) $record->is_active];
    }

    private function requiresActiveEndpoints(?DependencyEdge $existing, bool $requestedActive): bool
    {
        return $requestedActive && (! $existing instanceof DependencyEdge || ! $existing->is_active);
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
            ['counts' => $counts, 'rows' => $this->boundedRows($rows)],
            $counts['invalid'] === 0 ? RegisterImportStatus::Pending : RegisterImportStatus::Rejected,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function boundedRows(array $rows): array
    {
        $invalid = [];
        $categorized = [];
        foreach ($rows as $row) {
            if ($row['category'] === 'invalid') {
                $invalid[] = $row;
            } else {
                $categorized[] = $row;
            }
        }

        return array_slice([...$invalid, ...$categorized], 0, self::MAX_PREVIEW_ROWS);
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
