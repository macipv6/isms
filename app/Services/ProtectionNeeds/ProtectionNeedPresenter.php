<?php

namespace App\Services\ProtectionNeeds;

use App\Enums\RegisterImportKind;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
use App\Policies\ProjectRiskPolicy;
use App\Services\Registers\RegisterPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProtectionNeedPresenter
{
    /** @return array<string, mixed> */
    public function index(Request $request, IsmsProject $project): array
    {
        $filters = validator($request->query(), [
            'target_type' => ['nullable', Rule::in(['process', 'asset'])],
            'state' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'batch' => ['nullable', 'uuid'],
        ])->validate();
        $query = ProtectionNeed::query()->where('project_id', $project->id)
            ->with(['sources' => fn ($q) => $q->orderBy('id')->limit(20),
                'downgradeRequests' => fn ($q) => $q->where('status', 'pending')->orderBy('id')])
            ->orderBy('business_process_id')->orderBy('asset_id')->orderBy('objective');
        if (($filters['target_type'] ?? null) === 'process') {
            $query->whereNotNull('business_process_id');
        } elseif (($filters['target_type'] ?? null) === 'asset') {
            $query->whereNotNull('asset_id');
        }
        if (in_array($filters['state'] ?? null, ['active', 'inactive'], true)) {
            $active = $filters['state'] === 'active';
            $query->where(function ($query) use ($active): void {
                $query->whereIn('business_process_id', BusinessProcess::query()->select('id')->where('is_active', $active))
                    ->orWhereIn('asset_id', Asset::query()->select('id')->where('is_active', $active));
            });
        }
        $page = $query->paginate(25)->appends(array_intersect_key($filters, array_flip(['target_type', 'state', 'batch'])));
        $needs = collect($page->items());
        $sources = $needs->flatMap(fn (ProtectionNeed $need) => $need->sources);
        $edges = DependencyEdge::query()->where('project_id', $project->id)->whereIn('id', $sources->pluck('first_edge_id')->filter()->unique())->get(['id', 'target_process_id', 'target_asset_id'])->keyBy('id');
        $processIds = $needs->pluck('business_process_id')->merge($sources->pluck('origin_process_id'))->merge($edges->pluck('target_process_id'))->filter()->unique();
        $assetIds = $needs->pluck('asset_id')->merge($sources->pluck('origin_asset_id'))->merge($edges->pluck('target_asset_id'))->filter()->unique();
        $processes = BusinessProcess::query()->where('project_id', $project->id)->whereIn('id', $processIds)->get(['id', 'key', 'name', 'is_active'])->keyBy('id');
        $assets = Asset::query()->where('project_id', $project->id)->whereIn('id', $assetIds)->get(['id', 'key', 'name', 'is_active'])->keyBy('id');
        $allVersions = ProtectionNeed::query()->where('project_id', $project->id)
            ->where(fn ($query) => $query->whereIn('business_process_id', $needs->pluck('business_process_id')->filter()->unique())
                ->orWhereIn('asset_id', $needs->pluck('asset_id')->filter()->unique()))
            ->get(['business_process_id', 'asset_id', 'objective', 'lock_version']);
        $versions = [];
        foreach ($allVersions as $version) {
            $versions[$version->business_process_id ?? $version->asset_id][$version->objective->value] = $version->lock_version;
        }
        $writable = app(ProjectRiskPolicy::class)->write($request->user(), $project);
        $base = '/organizations/'.$project->organization_id.'/projects/'.$project->id;
        $rows = [];
        foreach ($needs as $need) {
            $targetType = $need->business_process_id ? 'process' : 'asset';
            $targetId = $need->business_process_id ?? $need->asset_id;
            $target = $targetType === 'process' ? $processes->get($targetId) : $assets->get($targetId);
            $sourceRows = [];
            foreach ($need->sources as $source) {
                $sourceType = $source->origin_process_id ? 'process' : 'asset';
                $origin = $sourceType === 'process' ? $processes->get($source->origin_process_id) : $assets->get($source->origin_asset_id);
                $firstEdge = $edges->get($source->first_edge_id);
                $next = $firstEdge?->target_process_id ? $processes->get($firstEdge->target_process_id) : $assets->get($firstEdge?->target_asset_id);
                $path = array_values(array_unique(array_filter([$origin?->key, $next?->key, $target?->key])));
                if ($next && $target && $next->id !== $target->id) {
                    array_splice($path, 2, 0, ['…']);
                }
                $sourceRows[] = [
                    'source' => ['type' => $sourceType, 'id' => $origin?->id, 'key' => $origin?->key ?? 'Unbekannt', 'name' => $origin?->name ?? 'Unbekannt'],
                    'level' => $source->origin_level->value,
                    'path' => $path,
                ];
            }
            usort($sourceRows, fn (array $a, array $b): int => [$a['source']['type'], $a['source']['key'], $a['source']['id']] <=> [$b['source']['type'], $b['source']['key'], $b['source']['id']]);
            $pending = $need->downgradeRequests->first();
            $actions = $writable ? [
                'rate' => route('protection-needs.rate', [$project->organization_id, $project->id, $targetType, $targetId], false),
                'approve' => route('protection-needs.approve', [$project->organization_id, $project->id, $targetType, $targetId], false),
                'requestDowngrade' => route('protection-needs.downgrades.store', [$project->organization_id, $project->id, $need->id], false),
            ] : [];
            $rows[] = [
                'id' => $need->id,
                'targetType' => $targetType,
                'targetId' => $targetId,
                'target' => ['key' => $target?->key ?? 'Unbekannt', 'name' => $target?->name ?? 'Unbekannt', 'active' => (bool) ($target?->is_active ?? false)],
                'objective' => $need->objective->value,
                'directLevel' => $need->direct_level?->value,
                'inheritedLevel' => $need->inherited_level?->value,
                'effectiveLevel' => $need->effective_level?->value,
                'status' => $need->status->value,
                'needsReview' => (bool) $need->needs_review,
                'lockVersion' => $need->lock_version,
                'expectedVersions' => array_merge(['confidentiality' => 0, 'integrity' => 0, 'availability' => 0], $versions[$targetId] ?? []),
                'directRationale' => mb_substr((string) $need->direct_rationale, 0, 10000),
                'sources' => $sourceRows,
                'downgrade' => $pending ? [
                    'id' => $pending->id,
                    'status' => $pending->status->value,
                    'requestedLevel' => $pending->requested_level->value,
                    'inheritedLevel' => $pending->inherited_level->value,
                    'canDecideInternal' => $writable && Gate::allows('decideDowngrade', [ProtectionNeed::class, $pending]),
                    'canRecordExternal' => $writable && Gate::allows('recordExternalDowngrade', [ProtectionNeed::class, $pending]),
                    'actions' => $writable ? ['decide' => route('protection-needs.downgrades.decide', [$project->organization_id, $project->id, $pending->id], false)] : [],
                ] : null,
                'actions' => $actions,
            ];
        }

        return [
            'organization' => $project->organization->only(['id', 'name']),
            'project' => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status->value],
            'filters' => ['targetType' => $filters['target_type'] ?? null, 'state' => $filters['state'] ?? 'all'],
            'capabilities' => ['write' => $writable, 'import' => $writable],
            'actions' => ['previewImport' => route('register-imports.preview', [$project->organization_id, $project->id, 'protection_needs'], false)],
            'needs' => ['data' => $rows, 'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => 25], 'links' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()]],
            'importPreview' => app(RegisterPresenter::class)->importPreview($request, $project, RegisterImportKind::ProtectionNeeds, $writable, $base),
        ];
    }
}
