<?php

namespace App\Services\Bcm;

use App\Http\BcmRouteScope;
use App\Models\Asset;
use App\Models\BcmConflictAcceptance;
use App\Models\BcmExercise;
use App\Models\BiaConfigurationVersion;
use App\Models\BiaRevision;
use App\Models\BusinessProcess;
use App\Models\ContinuityPlanRevision;
use App\Models\IsmsProject;
use App\Policies\ProjectBcmPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as ArrayPaginator;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BcmWorkspacePresenter
{
    public function __construct(private readonly BcmFreshness $freshness, private readonly BcmConsistencyChecker $checker) {}

    /** @return array<string, mixed> */
    public function base(Request $request, IsmsProject $project): array
    {
        $allowed = [];
        foreach (['page', 'history_page', 'sources_page', 'conflicts_page', 'results_page', 'assets_page', 'findings_page', 'measures_page'] as $key) {
            if ($request->query($key) !== null) {
                $allowed[$key] = $this->pageNumber($request, $key);
            }
        }
        $query = http_build_query($allowed);
        Inertia::resolveUrlUsing(fn (Request $r): string => $r->getPathInfo().($query !== '' ? '?'.$query : ''));
        $configuration = app(BiaConfigurationWorkflow::class)->current($project);

        return [
            'project' => $project->only(['id', 'organization_id', 'name', 'status', 'bcm_level']),
            'capabilities' => ['write' => app(ProjectBcmPolicy::class)->write(BcmRouteScope::actor($request), $project)],
            'configuration' => $configuration ? [...$configuration->only(['id', 'version', 'minutes', 'approved_at']), 'lock_version' => $project->bia_configuration_lock_version] : null,
            'overview' => [
                'processes' => BusinessProcess::query()->where('project_id', $project->id)->where('is_active', true)->count(),
                'assets' => Asset::query()->where('project_id', $project->id)->where('is_active', true)->count(),
                'bia_drafts' => BiaRevision::query()->where('project_id', $project->id)->where('status', 'draft')->count(),
                'bia_approved' => BiaRevision::query()->where('project_id', $project->id)->where('status', 'approved')->count(),
                'plan_drafts' => ContinuityPlanRevision::query()->where('project_id', $project->id)->where('status', 'draft')->count(),
                'plan_approved' => ContinuityPlanRevision::query()->where('project_id', $project->id)->where('status', 'approved')->count(),
                'exercises' => BcmExercise::query()->where('project_id', $project->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($count): int => (int) $count)->all(),
                ...$this->readiness($project),
            ],
            'entries' => $this->items($request, []), 'sources' => $this->items($request, [], 'sources_page'),
            'conflicts' => $this->items($request, [], 'conflicts_page'), 'history' => $this->history($request, BiaConfigurationVersion::query()->where('project_id', $project->id)),
            'lock_version' => $project->bia_configuration_lock_version,
        ];
    }

    /** @param array<string, bool> $capabilities
     * @return array<string, bool>
     */
    public function capabilities(bool $write, string $status, array $capabilities = []): array
    {
        return ['write' => $write, 'edit' => $write && $status === 'draft', 'approve' => $write && $status === 'draft', 'revise' => $write && $status === 'approved', ...$capabilities];
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function items(Request $request, array $items, string $pageName = 'page'): array
    {
        $page = $this->pageNumber($request, $pageName);

        return (new ArrayPaginator(array_slice($items, ($page - 1) * 25, 25), count($items), 25, $page, ['path' => $request->url(), 'pageName' => $pageName]))->toArray();
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @param  callable(T): array<string, mixed>  $present
     * @return array<string, mixed>
     */
    public function paginate(Request $request, Builder $query, callable $present, string $pageName = 'page'): array
    {
        $page = $query->paginate(25, ['*'], $pageName, $this->pageNumber($request, $pageName));
        $page->through($present);

        return $page->toArray();
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @return array<string, mixed>
     */
    public function history(Request $request, Builder $query): array
    {
        return $this->paginate($request, $query->orderByDesc('version'), fn (Model $revision): array => $revision->only(['id', 'version', 'status', 'approved_at']), 'history_page');
    }

    private function pageNumber(Request $request, string $key): int
    {
        $value = $request->query($key);

        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false ? max(1, min(1000000, (int) $value)) : 1;
    }

    /** Aggregate fresh latest heads, never mutable flags or page-only counts.
     * @return array<string, int>
     */
    private function readiness(IsmsProject $project): array
    {
        $counts = ['bia_review_required' => 0, 'plan_review_required' => 0, 'exercise_review_required' => 0, 'conflicts' => 0, 'blocking_conflicts' => 0];
        $biaHeads = BiaRevision::query()->where('project_id', $project->id)->where('status', 'approved')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bia_revisions as newer')->whereColumn('newer.business_impact_analysis_id', 'bia_revisions.business_impact_analysis_id')->where('newer.status', 'approved')->whereColumn('newer.version', '>', 'bia_revisions.version'));
        foreach ($biaHeads->cursor() as $revision) {
            if (! $this->freshness->bia($revision)['current']) {
                $counts['bia_review_required']++;
            }
        }
        $planHeads = ContinuityPlanRevision::query()->where('project_id', $project->id)->where('status', 'approved')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('continuity_plan_revisions as newer')->whereColumn('newer.continuity_plan_id', 'continuity_plan_revisions.continuity_plan_id')->where('newer.status', 'approved')->whereColumn('newer.version', '>', 'continuity_plan_revisions.version'));
        foreach ($planHeads->cursor() as $revision) {
            if (! $this->freshness->plan($revision)['current']) {
                $counts['plan_review_required']++;
            }
        }
        foreach (BcmExercise::query()->where('project_id', $project->id)->where('status', '!=', 'cancelled')->cursor() as $exercise) {
            if (! $this->freshness->exercise($exercise)['current']) {
                $counts['exercise_review_required']++;
            }
        }
        $latestBia = BiaRevision::query()->where('project_id', $project->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bia_revisions as newer')->whereColumn('newer.business_impact_analysis_id', 'bia_revisions.business_impact_analysis_id')->whereColumn('newer.version', '>', 'bia_revisions.version'));
        foreach ($latestBia->cursor() as $revision) {
            try {
                $accepted = BcmConflictAcceptance::query()->where('project_id', $project->id)->where('bia_revision_id', $revision->id)->pluck('fingerprint')->all();
                foreach ($this->checker->warnings($revision) as $warning) {
                    if (! in_array($warning->fingerprint, $accepted, true)) {
                        $counts['conflicts']++;
                        $counts['blocking_conflicts'] += (int) $warning->blocking;
                    }
                }
            } catch (ModelNotFoundException|NotFoundHttpException) {
                // Unavailable history is counted by freshness, not invented warnings.
            }
        }
        $counts['processes_missing_bia'] = BusinessProcess::query()->where('project_id', $project->id)->where('is_active', true)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('business_impact_analyses')->join('bia_revisions', 'bia_revisions.business_impact_analysis_id', '=', 'business_impact_analyses.id')->whereColumn('business_impact_analyses.business_process_id', 'business_processes.id')->where('bia_revisions.status', 'approved'))->count();
        $counts['assets_missing_recovery'] = Asset::query()->where('project_id', $project->id)->where('is_active', true)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('asset_recovery_objective_revisions')->whereColumn('asset_recovery_objective_revisions.asset_id', 'assets.id')->where('status', 'approved'))->count();

        return $counts;
    }
}
