<?php

namespace App\Services\Registers;

use App\Data\Dependencies\DependencyNode;
use App\Enums\AssetType;
use App\Enums\DependencyImportance;
use App\Enums\DependencyNodeType;
use App\Enums\ProjectStatus;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Dependencies\DependencyGraph;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RegisterPresenter
{
    private const PER_PAGE = 25;

    private const MAX_PREVIEW_ROWS = 200;

    private const MAX_TRAVERSAL_HITS = 200;

    public function __construct(private readonly DependencyGraph $graph) {}

    /** @return array<string, mixed> */
    public function processes(Request $request, Organization $organization, IsmsProject $project): array
    {
        $this->authorizePage($organization, $project);
        $validated = validator($request->query(), $this->baseRules())->validate();
        $query = BusinessProcess::query()
            ->select(['id', 'project_id', 'key', 'name', 'description', 'owner_name', 'is_active', 'updated_at'])
            ->where('project_id', $project->id);
        $this->applyBaseFilters($query, $validated);
        $paginator = $query->orderBy('key')->paginate(self::PER_PAGE)->withQueryString();
        $base = $this->baseUrl($organization, $project);

        return [
            ...$this->common($request, $organization, $project, RegisterImportKind::Processes, $validated),
            'processes' => $this->pagination($paginator, fn (BusinessProcess $process): array => [
                'id' => $process->id,
                'key' => $process->key,
                'name' => $process->name,
                'description' => $process->description,
                'owner_name' => $process->owner_name,
                'active' => $process->is_active,
                'updated_at' => $process->updated_at->toIso8601String(),
                'actions' => [
                    'update' => $base.'/processes/'.$process->id,
                    'status' => $base.'/processes/'.$process->id.'/status',
                ],
            ]),
            ...$this->editProcess($validated['edit'] ?? null, $project, $this->writable($organization, $project)),
        ];
    }

    /** @return array<string, mixed> */
    public function assets(Request $request, Organization $organization, IsmsProject $project): array
    {
        $this->authorizePage($organization, $project);
        $validated = validator($request->query(), [
            ...$this->baseRules(),
            'type' => ['nullable', Rule::enum(AssetType::class)],
        ])->validate();
        $query = Asset::query()
            ->select(['id', 'project_id', 'key', 'name', 'type', 'description', 'owner_name', 'is_active', 'updated_at'])
            ->where('project_id', $project->id);
        $this->applyBaseFilters($query, $validated);
        $query->when($validated['type'] ?? null, fn (Builder $builder, string $type) => $builder->where('type', $type));
        $paginator = $query->orderBy('key')->paginate(self::PER_PAGE)->withQueryString();
        $base = $this->baseUrl($organization, $project);

        return [
            ...$this->common($request, $organization, $project, RegisterImportKind::Assets, $validated),
            'filters' => [...$this->filters($validated), 'type' => $validated['type'] ?? null],
            'assets' => $this->pagination($paginator, fn (Asset $asset): array => [
                'id' => $asset->id,
                'key' => $asset->key,
                'name' => $asset->name,
                'type' => $asset->type->value,
                'description' => $asset->description,
                'owner_name' => $asset->owner_name,
                'active' => $asset->is_active,
                'updated_at' => $asset->updated_at->toIso8601String(),
                'actions' => [
                    'update' => $base.'/assets/'.$asset->id,
                    'status' => $base.'/assets/'.$asset->id.'/status',
                ],
            ]),
            ...$this->editAsset($validated['edit'] ?? null, $project, $this->writable($organization, $project)),
        ];
    }

    /** @return array<string, mixed> */
    public function dependencies(Request $request, Organization $organization, IsmsProject $project): array
    {
        $this->authorizePage($organization, $project);
        $validated = validator($request->query(), [
            ...$this->baseRules(),
            'source_type' => ['nullable', Rule::enum(DependencyNodeType::class)],
            'target_type' => ['nullable', Rule::enum(DependencyNodeType::class)],
            'importance' => ['nullable', Rule::enum(DependencyImportance::class)],
            'node_type' => ['nullable', 'required_with:node_key', Rule::enum(DependencyNodeType::class)],
            'node_key' => ['nullable', 'required_with:node_type', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/'],
            'direction' => ['nullable', Rule::in(['dependencies', 'dependents', 'affected_processes'])],
            'transitive' => ['nullable', Rule::in(['true', 'false'])],
            'include_inactive' => ['nullable', Rule::in(['true', 'false'])],
        ])->validate();
        $query = DependencyEdge::query()
            ->select([
                'id', 'project_id', 'source_process_id', 'source_asset_id', 'target_process_id', 'target_asset_id',
                'importance', 'reason', 'is_active', 'updated_at',
            ])
            ->where('project_id', $project->id)
            ->with([
                'sourceProcess:id,project_id,key,name', 'sourceAsset:id,project_id,key,name',
                'targetProcess:id,project_id,key,name', 'targetAsset:id,project_id,key,name',
            ]);
        $this->applyDependencyFilters($query, $validated);
        $paginator = $query->orderByDesc('created_at')->orderBy('id')->paginate(self::PER_PAGE)->withQueryString();
        $base = $this->baseUrl($organization, $project);

        return [
            ...$this->common($request, $organization, $project, RegisterImportKind::Dependencies, $validated),
            'filters' => [
                ...$this->filters($validated),
                'source_type' => $validated['source_type'] ?? null,
                'target_type' => $validated['target_type'] ?? null,
                'importance' => $validated['importance'] ?? null,
            ],
            'dependencies' => $this->pagination($paginator, fn (DependencyEdge $edge): array => [
                'id' => $edge->id,
                'source' => $this->edgeNode($edge, true),
                'target' => $this->edgeNode($edge, false),
                'importance' => $edge->importance->value,
                'reason' => $edge->reason,
                'active' => $edge->is_active,
                'updated_at' => $edge->updated_at->toIso8601String(),
                'actions' => [
                    'update' => $base.'/dependencies/'.$edge->id,
                    'status' => $base.'/dependencies/'.$edge->id.'/status',
                ],
            ]),
            'traversal' => $this->traversal($project, $validated),
            ...$this->editDependency($validated['edit'] ?? null, $project, $this->writable($organization, $project)),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function baseRules(): array
    {
        return [
            'key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            'state' => ['nullable', Rule::in(['active', 'inactive'])],
            'edit' => ['nullable', 'uuid'],
            'batch' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @template TModel of BusinessProcess|Asset
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $validated
     */
    private function applyBaseFilters(Builder $query, array $validated): void
    {
        $query->when($validated['key'] ?? null, fn (Builder $builder, string $key) => $builder->where('key', 'ilike', '%'.RegisterKey::normalize($key).'%'));
        if (isset($validated['state'])) {
            $query->where('is_active', $validated['state'] === 'active');
        }
    }

    /**
     * @param  Builder<DependencyEdge>  $query
     * @param  array<string, mixed>  $validated
     */
    private function applyDependencyFilters(Builder $query, array $validated): void
    {
        if (isset($validated['state'])) {
            $query->where('is_active', $validated['state'] === 'active');
        }
        $query->when($validated['importance'] ?? null, fn (Builder $builder, string $importance) => $builder->where('importance', $importance));
        foreach (['source', 'target'] as $side) {
            $type = $validated[$side.'_type'] ?? null;
            if ($type !== null) {
                $query->whereNotNull($side.'_'.$type.'_id');
            }
        }
        if (isset($validated['key'])) {
            $key = RegisterKey::normalize($validated['key']);
            $query->where(function (Builder $builder) use ($key): void {
                $builder->whereHas('sourceProcess', fn (Builder $relation) => $relation->where('key', 'ilike', '%'.$key.'%'))
                    ->orWhereHas('sourceAsset', fn (Builder $relation) => $relation->where('key', 'ilike', '%'.$key.'%'))
                    ->orWhereHas('targetProcess', fn (Builder $relation) => $relation->where('key', 'ilike', '%'.$key.'%'))
                    ->orWhereHas('targetAsset', fn (Builder $relation) => $relation->where('key', 'ilike', '%'.$key.'%'));
            });
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function common(Request $request, Organization $organization, IsmsProject $project, RegisterImportKind $kind, array $validated): array
    {
        $writable = $this->writable($organization, $project);
        $base = $this->baseUrl($organization, $project);

        return [
            'organization' => $organization->only(['id', 'name']),
            'project' => $project->only(['id', 'name']),
            'filters' => $this->filters($validated),
            'capabilities' => [
                'create' => $writable,
                'edit' => $writable,
                'changeStatus' => $writable,
                'import' => $writable,
                'traverse' => true,
            ],
            'actions' => [
                'store' => $base.'/'.$kind->value,
                'previewImport' => $base.'/imports/'.$kind->value.'/preview',
            ],
            'importPreview' => $this->importPreview($request, $project, $kind, $writable, $base),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{key: mixed, state: mixed}
     */
    private function filters(array $validated): array
    {
        return ['key' => $validated['key'] ?? null, 'state' => $validated['state'] ?? null];
    }

    /** @return array<string, mixed>|null */
    private function importPreview(Request $request, IsmsProject $project, RegisterImportKind $kind, bool $writable, string $base): ?array
    {
        $batchId = $request->query('batch');
        if (! is_string($batchId) || $batchId === '') {
            return null;
        }
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $batch = RegisterImportBatch::query()->find($batchId);
        abort_unless($batch instanceof RegisterImportBatch
            && $batch->project_id === $project->id
            && $batch->created_by === $actor->id
            && $batch->kind === $kind, 404);
        $summary = $batch->summary;
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
        $rows = is_array($summary['rows'] ?? null) ? $summary['rows'] : [];
        $expired = $batch->expires_at->getTimestamp() <= now('UTC')->getTimestamp();
        $invalid = max(0, (int) ($counts['invalid'] ?? 0));

        return [
            'id' => $batch->id,
            'kind' => $batch->kind->value,
            'status' => $batch->status->value,
            'counts' => [
                'new' => max(0, (int) ($counts['new'] ?? 0)),
                'changed' => max(0, (int) ($counts['changed'] ?? 0)),
                'unchanged' => max(0, (int) ($counts['unchanged'] ?? 0)),
                'invalid' => $invalid,
            ],
            'rows' => array_values(array_map(
                fn (array $row): array => $this->previewRow($row),
                array_slice(array_filter($rows, 'is_array'), 0, self::MAX_PREVIEW_ROWS),
            )),
            'expiresAt' => $batch->expires_at->toIso8601String(),
            'expired' => $expired,
            'canConfirm' => $writable && ! $expired && $invalid === 0 && $batch->status === RegisterImportStatus::Pending,
            'actions' => [
                'preview' => $base.'/imports/'.$kind->value.'/preview',
                'confirm' => $base.'/imports/'.$batch->id.'/confirm',
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function rowReference(array $row): ?string
    {
        $values = $row['values'] ?? null;
        if (! is_array($values)) {
            return null;
        }
        if (is_string($values['key'] ?? null) && preg_match('/^[A-Z0-9][A-Z0-9._-]{1,63}$/', $values['key']) === 1) {
            return $values['key'];
        }
        if (is_string($values['source_type'] ?? null) && is_string($values['source_key'] ?? null)
            && is_string($values['target_type'] ?? null) && is_string($values['target_key'] ?? null)
            && in_array($values['source_type'], ['process', 'asset'], true)
            && in_array($values['target_type'], ['process', 'asset'], true)
            && preg_match('/^[A-Z0-9][A-Z0-9._-]{1,63}$/', $values['source_key']) === 1
            && preg_match('/^[A-Z0-9][A-Z0-9._-]{1,63}$/', $values['target_key']) === 1) {
            return $values['source_type'].':'.$values['source_key'].' → '.$values['target_type'].':'.$values['target_key'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function previewRow(array $row): array
    {
        $categories = ['new', 'changed', 'unchanged', 'invalid'];
        $fields = ['file', 'key', 'name', 'description', 'owner_name', 'owner_email', 'active', 'type', 'source_type', 'source_key', 'target_type', 'target_key', 'importance', 'reason'];
        $codes = ['invalid_row', 'unknown_endpoint', 'inactive_endpoint', 'cycle'];
        $category = is_string($row['category'] ?? null) && in_array($row['category'], $categories, true) ? $row['category'] : 'invalid';
        $field = is_string($row['field'] ?? null) && in_array($row['field'], $fields, true) ? $row['field'] : null;
        $code = is_string($row['code'] ?? null) && in_array($row['code'], $codes, true) ? $row['code'] : null;

        return array_filter([
            'line' => isset($row['line']) ? max(1, (int) $row['line']) : null,
            'category' => $category,
            'field' => $field,
            'code' => $code,
            'reference' => $this->rowReference($row),
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function editProcess(mixed $id, IsmsProject $project, bool $writable): array
    {
        if (! is_string($id) || ! $writable) {
            return [];
        }
        $process = BusinessProcess::query()->where('project_id', $project->id)->find($id);
        abort_unless($process instanceof BusinessProcess, 404);

        return ['editRecord' => [
            'id' => $process->id, 'key' => $process->key, 'name' => $process->name, 'description' => $process->description,
            'owner_name' => $process->owner_name, 'owner_email' => $process->owner_email, 'active' => $process->is_active,
            'updated_at' => $process->updated_at->toIso8601String(),
        ]];
    }

    /** @return array<string, mixed> */
    private function editAsset(mixed $id, IsmsProject $project, bool $writable): array
    {
        if (! is_string($id) || ! $writable) {
            return [];
        }
        $asset = Asset::query()->where('project_id', $project->id)->find($id);
        abort_unless($asset instanceof Asset, 404);

        return ['editRecord' => [
            'id' => $asset->id, 'key' => $asset->key, 'name' => $asset->name, 'type' => $asset->type->value,
            'description' => $asset->description, 'owner_name' => $asset->owner_name, 'owner_email' => $asset->owner_email,
            'active' => $asset->is_active, 'updated_at' => $asset->updated_at->toIso8601String(),
        ]];
    }

    /** @return array<string, mixed> */
    private function editDependency(mixed $id, IsmsProject $project, bool $writable): array
    {
        if (! is_string($id) || ! $writable) {
            return [];
        }
        $edge = DependencyEdge::query()
            ->where('project_id', $project->id)
            ->with(['sourceProcess:id,project_id,key,name', 'sourceAsset:id,project_id,key,name', 'targetProcess:id,project_id,key,name', 'targetAsset:id,project_id,key,name'])
            ->find($id);
        abort_unless($edge instanceof DependencyEdge, 404);

        return ['editRecord' => [
            'id' => $edge->id,
            'source' => $this->edgeNode($edge, true),
            'target' => $this->edgeNode($edge, false),
            'importance' => $edge->importance->value,
            'reason' => $edge->reason,
            'active' => $edge->is_active,
            'updated_at' => $edge->updated_at->toIso8601String(),
        ]];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    private function traversal(IsmsProject $project, array $validated): ?array
    {
        if (! isset($validated['node_type'], $validated['node_key'])) {
            return null;
        }
        $type = DependencyNodeType::from($validated['node_type']);
        $key = RegisterKey::normalize($validated['node_key']);
        $node = $this->resolveNode($project, $type, $key);
        $direction = $validated['direction'] ?? 'dependencies';
        $transitive = ($validated['transitive'] ?? 'false') === 'true';
        $includeInactive = ($validated['include_inactive'] ?? 'false') === 'true';
        $hits = match ($direction) {
            'dependents' => $this->graph->dependents($project, $node, $transitive, $includeInactive),
            'affected_processes' => $this->graph->affectedProcesses($project, $node, $transitive, $includeInactive),
            default => $this->graph->dependencies($project, $node, $transitive, $includeInactive),
        };
        $hits = array_slice($hits, 0, self::MAX_TRAVERSAL_HITS);
        $processNames = BusinessProcess::query()->where('project_id', $project->id)->whereIn('key', array_map(fn ($hit) => $hit->node->key, $hits))->pluck('name', 'key');
        $assetNames = Asset::query()->where('project_id', $project->id)->whereIn('key', array_map(fn ($hit) => $hit->node->key, $hits))->pluck('name', 'key');

        return [
            'selected' => ['type' => $type->value, 'key' => $key],
            'direction' => $direction,
            'transitive' => $transitive,
            'includeInactive' => $includeInactive,
            'hits' => array_map(fn ($hit): array => [
                'type' => $hit->node->type->value,
                'key' => $hit->node->key,
                'name' => ($hit->node->type === DependencyNodeType::Process ? $processNames : $assetNames)->get($hit->node->key),
                'depth' => $hit->depth,
                'importance' => $hit->importance->value,
            ], $hits),
        ];
    }

    private function resolveNode(IsmsProject $project, DependencyNodeType $type, string $key): DependencyNode
    {
        if ($type === DependencyNodeType::Process) {
            $process = BusinessProcess::query()->where('project_id', $project->id)->where('key', $key)->first();
            abort_unless($process instanceof BusinessProcess, 404);

            return DependencyNode::process($process);
        }
        $asset = Asset::query()->where('project_id', $project->id)->where('key', $key)->first();
        abort_unless($asset instanceof Asset, 404);

        return DependencyNode::asset($asset);
    }

    /** @return array{type: string, key: string, name: string} */
    private function edgeNode(DependencyEdge $edge, bool $source): array
    {
        $process = $source ? $edge->sourceProcess : $edge->targetProcess;
        if ($process instanceof BusinessProcess) {
            return ['type' => 'process', 'key' => $process->key, 'name' => $process->name];
        }
        $asset = $source ? $edge->sourceAsset : $edge->targetAsset;
        abort_unless($asset instanceof Asset, 500);

        return ['type' => 'asset', 'key' => $asset->key, 'name' => $asset->name];
    }

    /**
     * @template TModel
     *
     * @param  LengthAwarePaginator<int, TModel>  $paginator
     * @param  callable(TModel): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function pagination(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => array_map($map, $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(), 'total' => $paginator->total(),
            ],
            'links' => ['previous' => $paginator->previousPageUrl(), 'next' => $paginator->nextPageUrl()],
        ];
    }

    private function authorizePage(Organization $organization, IsmsProject $project): void
    {
        abort_unless($organization->organization_type === 'customer' && $project->organization_id === $organization->id, 404);
        Gate::authorize('viewAssessment', $project);
    }

    private function writable(Organization $organization, IsmsProject $project): bool
    {
        return $organization->is_active && in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Active], true);
    }

    private function baseUrl(Organization $organization, IsmsProject $project): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id;
    }
}
