<?php

namespace App\Services\ProtectionNeeds;

use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
use App\Models\RegisterImportBatch;
use App\Policies\ProjectRiskPolicy;
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
            ->with(['sources' => fn ($q) => $q->limit(20)])
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
        $rows = [];
        foreach ($page->items() as $need) {
            $sources = [];
            foreach ($need->sources as $source) {
                $sources[] = [
                    'id' => $source->id,
                    'sourceType' => $source->origin_process_id ? 'process' : 'asset',
                    'sourceId' => $source->origin_process_id ?? $source->origin_asset_id,
                    'level' => $source->origin_level->value,
                ];
            }
            $rows[] = [
                'id' => $need->id,
                'targetType' => $need->business_process_id ? 'process' : 'asset',
                'targetId' => $need->business_process_id ?? $need->asset_id,
                'objective' => $need->objective->value,
                'directLevel' => $need->direct_level?->value,
                'inheritedLevel' => $need->inherited_level?->value,
                'effectiveLevel' => $need->effective_level?->value,
                'status' => $need->status->value,
                'needsReview' => (bool) $need->needs_review,
                'lockVersion' => $need->lock_version,
                'directRationale' => mb_substr((string) $need->direct_rationale, 0, 10000),
                'sources' => $sources,
            ];
        }
        $batch = null;
        if (isset($filters['batch'])) {
            $batch = RegisterImportBatch::query()->where('project_id', $project->id)->whereKey($filters['batch'])->firstOrFail();
            abort_unless($batch->kind->value === 'protection_needs', 404);
            Gate::authorize('view', $batch);
        }

        return [
            'project' => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status->value],
            'filters' => ['targetType' => $filters['target_type'] ?? null, 'state' => $filters['state'] ?? 'all'],
            'capabilities' => ['write' => app(ProjectRiskPolicy::class)->write($request->user(), $project), 'import' => app(ProjectRiskPolicy::class)->write($request->user(), $project)],
            'needs' => ['data' => $rows, 'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => 25], 'links' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()]],
            'importBatch' => $batch ? ['id' => $batch->id, 'status' => $batch->status->value, 'summary' => array_diff_key($batch->summary, ['state_fingerprint' => true])] : null,
        ];
    }
}
