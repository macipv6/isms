<?php

namespace App\Services\Bcm;

use App\Models\BcmExercise;
use App\Models\BcmExerciseCriterion;
use App\Models\BcmExerciseResult;
use App\Models\Finding;
use App\Models\IsmsProject;
use Illuminate\Http\Request;

class BcmExercisePresenter
{
    public function __construct(private readonly BcmWorkspacePresenter $workspace, private readonly BcmFreshness $freshness, private readonly ContinuityPlanPresenter $plans) {}

    /** @return array<string, mixed> */
    public function index(Request $request, IsmsProject $project): array
    {
        $props = $this->workspace->base($request, $project);
        $props['exercise'] = null;
        $props['entries'] = $this->workspace->paginate($request, BcmExercise::query()->where('project_id', $project->id)->orderByDesc('created_at')->orderBy('id'), $this->summary(...));

        return $props;
    }

    /** @return array<string, mixed> */
    public function show(Request $request, IsmsProject $project, BcmExercise $exercise): array
    {
        $props = $this->workspace->base($request, $project);
        $write = $props['capabilities']['write'];
        $status = $exercise->status->value;
        $props['exercise'] = [...$this->summary($exercise), ...$exercise->only(['exercise_type', 'leader_name', 'participants', 'actual_at', 'completed_by', 'completed_at', 'cancelled_by', 'cancelled_at', 'cancelled_reason', 'source_change_acknowledged_by', 'source_change_acknowledged_at', 'source_change_reason']),
            'criteria' => $exercise->criteria()->orderBy('position')->limit(200)->get()->map(fn (BcmExerciseCriterion $row): array => $row->only(['id', 'position', 'expected_result', 'expected_minutes']))->all(),
            'evidence_ids' => $exercise->evidenceLinks()->orderBy('evidence_id')->limit(200)->pluck('evidence_id')->all(),
            'lists_complete' => $exercise->criteria()->count() <= 200 && $exercise->evidenceLinks()->count() <= 200,
        ];
        $props['capabilities'] = ['write' => $write, 'edit' => $write && $status === 'draft' && $props['exercise']['lists_complete'],
            'plan' => $write && $status === 'draft', 'record' => $write && in_array($status, ['planned', 'conducted'], true),
            'complete' => $write && $status === 'conducted', 'cancel' => $write && in_array($status, ['draft', 'planned', 'conducted'], true)];
        $props['results'] = $this->workspace->paginate($request, BcmExerciseResult::query()->where('project_id', $project->id)->where('bcm_exercise_id', $exercise->id)->orderBy('bcm_exercise_criterion_id'), function (BcmExerciseResult $result) use ($request, $write, $status): array {
            $history = Finding::query()->where('project_id', $result->project_id)->where('bcm_exercise_result_id', $result->id);
            $hasHistory = (clone $history)->exists();
            $nonpassed = $result->outcome !== null && $result->outcome->value !== 'passed';
            $conducted = $write && $status === 'conducted';

            return [...$result->only(['id', 'outcome', 'observations', 'reason', 'actual_minutes', 'finding_waiver_reason', 'finding_waived_by', 'finding_waived_at']),
                'criterion_id' => $result->bcm_exercise_criterion_id,
                'capabilities' => ['edit' => $conducted && ! $hasHistory, 'propose_finding' => $conducted && $nonpassed && $result->finding_waived_at === null, 'waive_finding' => $conducted && $nonpassed && ! $hasHistory],
                'findings' => $this->workspace->paginate($request, $history->orderByDesc('created_at')->orderBy('id'), fn (Finding $finding): array => [...$finding->only(['id', 'title', 'severity', 'status']), 'measures' => $this->workspace->paginate($request, $finding->measures()->getQuery()->orderBy('id'), fn ($measure): array => $measure->only(['id', 'title', 'status']), 'measures_page')], 'findings_page'),
            ];
        }, 'results_page');
        $props['sources'] = $this->workspace->items($request, $this->plans->sourceRows($exercise->planRevision), 'sources_page');
        $props['history'] = $this->workspace->paginate($request, BcmExercise::query()->where('project_id', $project->id)->where('continuity_plan_revision_id', $exercise->continuity_plan_revision_id)->orderByDesc('created_at')->orderBy('id'), fn (BcmExercise $row): array => $row->only(['id', 'status', 'planned_at', 'completed_at', 'cancelled_at']), 'history_page');
        $props['lock_version'] = $exercise->lock_version;

        return $props;
    }

    /** @return array<string, mixed> */
    private function summary(BcmExercise $exercise): array
    {
        return [...$exercise->only(['id', 'status', 'lock_version', 'scenario', 'planned_at']), 'plan_revision_id' => $exercise->continuity_plan_revision_id, 'freshness' => $this->freshness->exercise($exercise)];
    }
}
