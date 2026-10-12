<?php

namespace App\Services\Bcm;

use App\Models\Asset;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\BcmConflictAcceptance;
use App\Models\BiaRevision;
use App\Models\BusinessImpactAnalysis;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BiaPresenter
{
    public function __construct(private readonly BcmWorkspacePresenter $workspace, private readonly BcmFreshness $freshness, private readonly BcmConsistencyChecker $checker, private readonly BcmSourceSnapshot $sources) {}

    /** @return array<string, mixed> */
    public function index(Request $request, IsmsProject $project): array
    {
        $props = $this->workspace->base($request, $project);
        $props['revision'] = null;
        $props['entries'] = $this->workspace->paginate($request, BusinessProcess::query()->where('project_id', $project->id)->orderBy('name')->orderBy('id'), function (BusinessProcess $process): array {
            $analysis = BusinessImpactAnalysis::query()->where('project_id', $process->project_id)->where('business_process_id', $process->id)->first();
            $revisions = BiaRevision::query()->where('project_id', $process->project_id)->where('business_impact_analysis_id', $analysis?->id)->orderByDesc('version');
            $draft = (clone $revisions)->where('status', 'draft')->first();
            $approved = (clone $revisions)->where('status', 'approved')->first();

            return [...$process->only(['id', 'name', 'is_active']), 'draft' => $draft ? $this->summary($draft) : null, 'approved' => $approved ? $this->summary($approved) : null];
        });
        $props['assets'] = $this->workspace->paginate($request, Asset::query()->where('project_id', $project->id)->orderBy('name')->orderBy('id'), fn (Asset $asset): array => $asset->only(['id', 'name', 'is_active']), 'assets_page');

        return $props;
    }

    /** @return array<string, mixed> */
    public function show(Request $request, IsmsProject $project, BiaRevision $revision): array
    {
        $props = $this->workspace->base($request, $project);
        $props['revision'] = [...$this->summary($revision), ...$revision->only(['business_impact_analysis_id', 'bia_configuration_version_id', 'owner_name', 'mtpd_minutes', 'rto_minutes', 'rpo_minutes', 'rpo_not_applicable', 'rpo_reason', 'target_reason', 'approved_at']),
            'process' => $revision->analysis->process->only(['id', 'name', 'is_active']),
            'minutes' => $revision->configuration->minutes,
            'impacts' => $revision->impacts()->orderBy('area')->orderBy('minutes')->limit(48)->get()->map(fn ($impact): array => $impact->only(['area', 'minutes', 'level', 'reason']))->all()];
        $active = $revision->analysis->process->is_active;
        $props['capabilities'] = $this->workspace->capabilities($props['capabilities']['write'] && $active, $revision->status->value);
        $props['history'] = $this->workspace->history($request, BiaRevision::query()->where('project_id', $project->id)->where('business_impact_analysis_id', $revision->business_impact_analysis_id));
        $props['lock_version'] = $revision->lock_version;
        try {
            $raw = $revision->status->value === 'approved' ? ($revision->source_snapshot['sources'] ?? []) : $this->sources->bia($revision);
            $sourceRows = $this->dependencyRows($revision, $raw['dependencies'] ?? []);
            $props['sources'] = $this->workspace->items($request, $sourceRows, 'sources_page');
            $accepted = BcmConflictAcceptance::query()->where('project_id', $project->id)->where('bia_revision_id', $revision->id)->pluck('id', 'fingerprint')->all();
            $rows = array_map(fn ($warning): array => ['code' => $warning->code, 'target_type' => $warning->targetType->value, 'target_id' => $warning->targetId, 'revision_id' => $warning->revisionId, 'blocking' => $warning->blocking, 'fingerprint' => $warning->fingerprint, 'accepted' => isset($accepted[$warning->fingerprint]), 'acceptance_id' => $accepted[$warning->fingerprint] ?? null], $this->checker->warnings($revision));
            $props['conflicts'] = $this->workspace->items($request, $rows, 'conflicts_page');
        } catch (ModelNotFoundException|NotFoundHttpException) {
            // Inactive historical roots are readable without manufacturing new sources.
        }

        return $props;
    }

    /** @return array<string, mixed> */
    public function asset(Request $request, IsmsProject $project, Asset $asset, ?AssetRecoveryObjectiveRevision $revision): array
    {
        $props = $this->workspace->base($request, $project);
        $props['asset'] = $asset->only(['id', 'name', 'is_active']);
        $query = AssetRecoveryObjectiveRevision::query()->where('project_id', $project->id)->where('asset_id', $asset->id);
        $props['history'] = $this->workspace->history($request, clone $query);
        $revision ??= $query->orderByDesc('version')->first();
        $props['revision'] = $revision ? $revision->only(['id', 'version', 'lock_version', 'status', 'rto_minutes', 'rpo_minutes', 'rpo_not_applicable', 'rpo_reason', 'target_reason', 'approved_at']) : null;
        $props['lock_version'] = $revision ? $revision->lock_version : 0;
        $props['capabilities'] = $this->workspace->capabilities($props['capabilities']['write'] && $asset->is_active, $revision?->status->value ?? 'missing', ['draft' => $props['capabilities']['write'] && $asset->is_active]);
        if ($revision) {
            $current = $asset->is_active && $revision->status->value === 'approved' && ! (clone $query)->where('status', 'approved')->where('version', '>', $revision->version)->exists();
            $props['revision']['freshness'] = ['current' => $current, 'reasons' => $current ? [] : ['review_required']];
        }

        return $props;
    }

    /** @return array<string, mixed> */
    private function summary(BiaRevision $revision): array
    {
        return [...$revision->only(['id', 'version', 'status', 'lock_version']), 'freshness' => $this->freshness->bia($revision)];
    }

    /** Historical JSON is projected only through scoped server-owned identities.
     * @param  list<array<string, mixed>>  $dependencies
     * @return list<array<string, mixed>>
     */
    private function dependencyRows(BiaRevision $revision, array $dependencies): array
    {
        $rows = [];
        foreach ($dependencies as $source) {
            $type = $source['target_type'];
            $targetClass = match ($type) {
                'process' => BusinessProcess::class,
                'asset' => Asset::class,
                default => null,
            };
            if ($targetClass === null || ! $targetClass::query()->where('project_id', $revision->project_id)->whereKey($source['target_id'])->exists()) {
                continue;
            }
            $class = $type === 'process' ? BiaRevision::class : AssetRecoveryObjectiveRevision::class;
            $objective = isset($source['objective']['id']) ? $class::query()->where('project_id', $revision->project_id)->find($source['objective']['id']) : null;
            if (isset($source['objective']['id']) && ! $objective) {
                continue;
            }
            if ($objective) {
                $targetId = $objective instanceof BiaRevision ? $objective->analysis->business_process_id : $objective->asset_id;
                if ($targetId !== $source['target_id']) {
                    continue;
                }
            }
            $rows[] = ['target_type' => $type, 'target_id' => $source['target_id'], 'revision_id' => $objective?->id, 'version' => $objective?->version, 'critical' => (bool) $source['critical']];
        }

        return $rows;
    }
}
