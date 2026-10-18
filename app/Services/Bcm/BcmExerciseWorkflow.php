<?php

namespace App\Services\Bcm;

use App\Enums\BcmExerciseOutcome;
use App\Enums\BcmExerciseStatus;
use App\Enums\BcmRevisionStatus;
use App\Models\BcmExercise;
use App\Models\BcmExerciseResult;
use App\Models\ContinuityPlanRevision;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BcmExerciseWorkflow
{
    public function __construct(private readonly BcmProjectAccess $access, private readonly BcmExerciseReleaseValidator $validator, private readonly BcmSourceSnapshot $sources, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(ContinuityPlanRevision $plan, array $data, User $actor): BcmExercise
    {
        return DB::transaction(function () use ($plan, $data, $actor): BcmExercise {
            $project = $this->access->lockedWritable(IsmsProject::query()->findOrFail($plan->project_id), $actor);
            $plan = ContinuityPlanRevision::query()->whereKey($plan->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            if ($plan->status !== BcmRevisionStatus::Approved) {
                throw ValidationException::withMessages(['plan' => 'Eine freigegebene Planrevision ist erforderlich.']);
            }
            $data = $this->validator->draftData($data);
            $exercise = BcmExercise::query()->create(['project_id' => $project->id, 'continuity_plan_revision_id' => $plan->id, 'created_by' => $actor->id, 'status' => BcmExerciseStatus::Draft, 'lock_version' => 1, 'source_snapshot' => []]);
            $this->applyDraft($exercise, $data);
            $exercise->save();
            $this->audit('created', $exercise, $project, $actor, array_keys($data));

            return $exercise;
        });
    }

    /** @param array<string, mixed> $data */
    public function save(BcmExercise $exercise, array $data, int $expectedLockVersion, User $actor): BcmExercise
    {
        return DB::transaction(function () use ($exercise, $data, $expectedLockVersion, $actor): BcmExercise {
            [$project, $exercise] = $this->locked($exercise, $expectedLockVersion, $actor, [BcmExerciseStatus::Draft]);
            $data = $this->validator->draftData($data);
            $changed = $this->applyDraft($exercise, $data);
            if ($changed || $exercise->isDirty()) {
                $exercise->lock_version++;
                $exercise->save();
                $this->audit('saved', $exercise, $project, $actor, array_keys($data));
            }

            return $exercise;
        });
    }

    public function plan(BcmExercise $exercise, int $expectedLockVersion, User $actor): BcmExercise
    {
        return DB::transaction(function () use ($exercise, $expectedLockVersion, $actor): BcmExercise {
            [$project, $exercise] = $this->locked($exercise, $expectedLockVersion, $actor, [BcmExerciseStatus::Draft]);
            $this->sources->lockProjectSources($project);
            $plan = $this->validator->planning($exercise);
            $exercise->forceFill(['source_snapshot' => ['plan_revision_id' => $plan->id, 'plan_version' => $plan->version, 'plan_sources' => $plan->source_snapshot], 'status' => BcmExerciseStatus::Planned, 'lock_version' => $exercise->lock_version + 1])->save();
            $this->audit('planned', $exercise, $project, $actor, ['status', 'source_snapshot']);

            return $exercise;
        });
    }

    /** @param array<string, mixed> $data */
    public function record(BcmExercise $exercise, array $data, int $expectedLockVersion, User $actor): BcmExercise
    {
        return DB::transaction(function () use ($exercise, $data, $expectedLockVersion, $actor): BcmExercise {
            [$project, $exercise] = $this->locked($exercise, $expectedLockVersion, $actor, [BcmExerciseStatus::Planned, BcmExerciseStatus::Conducted]);
            $this->sources->lockProjectSources($project);
            $data = $this->validator->resultData($data);
            if ($exercise->status === BcmExerciseStatus::Planned) {
                $this->validator->currentPlan($exercise);
            }
            $changed = $this->evidence($exercise, $data);
            foreach ($data['results'] ?? [] as $row) {
                $criterion = $exercise->criteria()->whereKey($row['criterion_id'])->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
                $result = $exercise->results()->where('bcm_exercise_criterion_id', $criterion->id)->lockForUpdate()->first();
                $result ??= new BcmExerciseResult(['project_id' => $project->id, 'bcm_exercise_id' => $exercise->id, 'bcm_exercise_criterion_id' => $criterion->id]);
                unset($row['criterion_id']);
                $result->fill($row);
                if (! $result->exists || $result->isDirty()) {
                    if ($result->exists && $result->findings()->exists()) {
                        throw new ConflictHttpException('Ergebnisse mit Feststellungshistorie können nicht geändert werden.');
                    }
                    $result->forceFill(['finding_waiver_reason' => null, 'finding_waived_by' => null, 'finding_waived_at' => null])->save();
                    $changed = true;
                }
            }
            if (array_key_exists('actual_at', $data)) {
                $exercise->actual_at = $data['actual_at'];
            }
            if (array_key_exists('source_change_reason', $data)) {
                $reason = $this->validator->reason($data['source_change_reason'], 'source_change_reason');
                $exercise->forceFill(['source_change_reason' => $reason, 'source_change_acknowledged_by' => $actor->id, 'source_change_acknowledged_at' => now(), 'source_snapshot' => [...$exercise->source_snapshot, 'acknowledgement_sources' => $this->sources->exercise($exercise)]]);
            }
            $exercise->status = BcmExerciseStatus::Conducted;
            if ($changed || $exercise->isDirty()) {
                $exercise->lock_version++;
                $exercise->save();
                $this->audit('recorded', $exercise, $project, $actor, array_values(array_unique(['status', ...array_keys($data)])));
            }

            return $exercise;
        });
    }

    public function waiveFinding(BcmExerciseResult $result, string $reason, int $expectedLockVersion, User $actor): BcmExerciseResult
    {
        return DB::transaction(function () use ($result, $reason, $expectedLockVersion, $actor): BcmExerciseResult {
            $parent = BcmExercise::query()->whereKey($result->bcm_exercise_id)->where('project_id', $result->project_id)->firstOrFail();
            [$project, $exercise] = $this->locked($parent, $expectedLockVersion, $actor, [BcmExerciseStatus::Conducted]);
            $result = $exercise->results()->whereKey($result->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            $reason = $this->validator->reason($reason, 'finding_waiver_reason');
            if ($result->outcome === null || $result->outcome === BcmExerciseOutcome::Passed || $result->findings()->exists()) {
                throw ValidationException::withMessages(['finding_waiver_reason' => 'Nur Abweichungen ohne Feststellung können einen Verzicht erhalten.']);
            }
            if ($result->finding_waiver_reason === $reason && $result->finding_waived_by !== null && $result->finding_waived_at !== null) {
                return $result;
            }
            $result->forceFill(['finding_waiver_reason' => $reason, 'finding_waived_by' => $actor->id, 'finding_waived_at' => now()])->save();
            $exercise->increment('lock_version');
            $this->audit('finding_waived', $exercise, $project, $actor, ['finding_waiver_reason'], $result->id);

            return $result;
        });
    }

    public function complete(BcmExercise $exercise, int $expectedLockVersion, User $actor): BcmExercise
    {
        return DB::transaction(function () use ($exercise, $expectedLockVersion, $actor): BcmExercise {
            [$project, $exercise] = $this->locked($exercise, $expectedLockVersion, $actor, [BcmExerciseStatus::Conducted]);
            $this->sources->lockProjectSources($project);
            $this->validator->complete($exercise);
            $exercise->forceFill(['status' => BcmExerciseStatus::Completed, 'completed_by' => $actor->id, 'completed_at' => now(), 'lock_version' => $exercise->lock_version + 1])->save();
            $this->audit('completed', $exercise, $project, $actor, ['status']);

            return $exercise;
        });
    }

    public function cancel(BcmExercise $exercise, string $reason, int $expectedLockVersion, User $actor): BcmExercise
    {
        return DB::transaction(function () use ($exercise, $reason, $expectedLockVersion, $actor): BcmExercise {
            [$project, $exercise] = $this->locked($exercise, $expectedLockVersion, $actor, [BcmExerciseStatus::Draft, BcmExerciseStatus::Planned, BcmExerciseStatus::Conducted]);
            $reason = $this->validator->reason($reason, 'cancelled_reason');
            $exercise->forceFill(['status' => BcmExerciseStatus::Cancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancelled_reason' => $reason, 'lock_version' => $exercise->lock_version + 1])->save();
            $this->audit('cancelled', $exercise, $project, $actor, ['status']);

            return $exercise;
        });
    }

    /** @param list<BcmExerciseStatus> $allowed
     * @return array{IsmsProject, BcmExercise}
     */
    private function locked(BcmExercise $exercise, int $expectedLockVersion, User $actor, array $allowed): array
    {
        $project = $this->access->lockedWritable(IsmsProject::query()->findOrFail($exercise->project_id), $actor);
        $exercise = BcmExercise::query()->whereKey($exercise->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
        $this->access->assertVersion($exercise, $expectedLockVersion);
        if (! in_array($exercise->status, $allowed, true)) {
            throw new ConflictHttpException('Dieser Statuswechsel ist nicht zulässig. Abgeschlossene Historie ist unveränderlich.');
        }

        return [$project, $exercise];
    }

    /** @param array<string, mixed> $data */
    private function applyDraft(BcmExercise $exercise, array $data): bool
    {
        $changed = $this->evidence($exercise, $data);
        if (array_key_exists('criteria', $data)) {
            $rows = [];
            foreach ($data['criteria'] as $index => $row) {
                $rows[] = ['position' => $index + 1, ...array_replace(['expected_result' => null, 'expected_minutes' => null], $row)];
            }
            $existing = $exercise->criteria()->orderBy('position')->get()->map(fn ($criterion) => $criterion->only(['position', 'expected_result', 'expected_minutes']))->all();
            if ($rows !== $existing) {
                $exercise->criteria()->delete();
                foreach ($rows as $row) {
                    $exercise->criteria()->create([...$row, 'project_id' => $exercise->project_id]);
                }
                $changed = true;
            }
        }
        $exercise->fill(array_diff_key($data, array_flip(['criteria', 'evidence_ids'])));

        return $changed;
    }

    /** @param array<string, mixed> $data */
    private function evidence(BcmExercise $exercise, array $data): bool
    {
        if (! array_key_exists('evidence_ids', $data)) {
            return false;
        }
        $ids = $data['evidence_ids'];
        sort($ids);
        foreach ($ids as $id) {
            EvidenceFile::query()->whereKey($id)->where('project_id', $exercise->project_id)->lockForUpdate()->firstOrFail();
        }
        if ($ids === $exercise->evidenceLinks()->orderBy('evidence_id')->pluck('evidence_id')->all()) {
            return false;
        }
        $exercise->evidenceLinks()->delete();
        foreach ($ids as $id) {
            $exercise->evidenceLinks()->create(['project_id' => $exercise->project_id, 'evidence_id' => $id]);
        }

        return true;
    }

    /** @param list<string> $fields */
    private function audit(string $action, BcmExercise $exercise, IsmsProject $project, User $actor, array $fields, ?string $resultId = null): void
    {
        $this->audit->record('bcm.exercise.'.$action, $actor, ['project_id' => $project->id, 'source_id' => $exercise->id, 'target_id' => $resultId, 'new_status' => $exercise->status->value, 'changed_fields' => $fields], $project->organization_id);
    }
}
