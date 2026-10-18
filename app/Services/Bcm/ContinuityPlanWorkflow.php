<?php

namespace App\Services\Bcm;

use App\Enums\BcmRevisionStatus;
use App\Models\BusinessProcess;
use App\Models\ContinuityPlan;
use App\Models\ContinuityPlanRevision;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\RiskAnalysis;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ContinuityPlanWorkflow
{
    public function __construct(private readonly BcmProjectAccess $access, private readonly ContinuityPlanReleaseValidator $validator, private readonly BcmSourceSnapshot $sources, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(IsmsProject $project, array $data, User $actor): ContinuityPlanRevision
    {
        return DB::transaction(function () use ($project, $data, $actor) {
            $project = $this->access->lockedWritable($project, $actor);
            $data = $this->validator->draftData($data);
            $plan = ContinuityPlan::query()->create(['project_id' => $project->id]);
            $revision = $plan->revisions()->create(['project_id' => $project->id, 'version' => 1, 'lock_version' => 1, 'status' => BcmRevisionStatus::Draft, 'created_by' => $actor->id, 'source_snapshot' => []]);
            $this->apply($revision, $data);
            $this->validator->validateReferences($revision);
            $revision->save();
            $this->record('created', $revision, $project, $actor, array_keys($data));

            return $revision;
        });
    }

    public function revise(ContinuityPlanRevision $revision, User $actor): ContinuityPlanRevision
    {
        return DB::transaction(function () use ($revision, $actor) {
            $project = $this->access->lockedWritable(IsmsProject::query()->findOrFail($revision->project_id), $actor);
            $revision = ContinuityPlanRevision::query()->whereKey($revision->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            if ($revision->status !== BcmRevisionStatus::Approved) {
                throw new ConflictHttpException('Nur freigegebene Pläne können einen Folgeentwurf erhalten.');
            }
            $existing = ContinuityPlanRevision::query()->where('continuity_plan_id', $revision->continuity_plan_id)->where('project_id', $project->id)->where('status', 'draft')->lockForUpdate()->first();
            if ($existing) {
                $this->validator->validateReferences($existing, requireActiveProcesses: false);

                return $existing;
            }
            $draft = $revision->plan->revisions()->create([...$revision->only(['title', 'scenario', 'activation_conditions', 'strategy', 'minimum_operation', 'normal_operation_return']), 'project_id' => $project->id, 'version' => ((int) $revision->plan->revisions()->max('version')) + 1, 'lock_version' => 1, 'status' => BcmRevisionStatus::Draft, 'created_by' => $actor->id, 'source_snapshot' => []]);
            foreach (['steps', 'roles', 'processes', 'evidenceLinks', 'riskLinks'] as $relation) {
                foreach ($revision->{$relation}()->get() as $child) {
                    $copy = $child->replicate();
                    $copy->setAttribute('continuity_plan_revision_id', $draft->id);
                    if ($relation === 'processes') {
                        $copy->setAttribute('bia_revision_id', null);
                    }
                    $copy->save();
                }
            }
            $this->validator->validateReferences($draft, requireActiveProcesses: false);
            $this->record('revised', $draft, $project, $actor, []);

            return $draft;
        });
    }

    /** @param array<string, mixed> $data */
    public function save(ContinuityPlanRevision $revision, array $data, int $expectedLockVersion, User $actor): ContinuityPlanRevision
    {
        return DB::transaction(function () use ($revision, $data, $expectedLockVersion, $actor) {
            [$project, $revision] = $this->locked($revision, $expectedLockVersion, $actor);
            $data = $this->validator->draftData($data);
            $changed = $this->apply($revision, $data);
            $this->validator->validateReferences($revision);
            if ($changed || $revision->isDirty()) {
                $revision->lock_version++;
                $revision->save();
                $this->record('saved', $revision, $project, $actor, array_keys($data));
            }

            return $revision;
        });
    }

    public function approve(ContinuityPlanRevision $revision, int $expectedLockVersion, User $actor): ContinuityPlanRevision
    {
        return DB::transaction(function () use ($revision, $expectedLockVersion, $actor) {
            [$project, $revision] = $this->locked($revision, $expectedLockVersion, $actor);
            $this->sources->lockProjectSources($project);
            $snapshot = $this->validator->validate($revision);
            foreach ($snapshot['processes'] as $process) {
                $revision->processes()->where('business_process_id', $process['process_id'])->update(['bia_revision_id' => $process['revision_id']]);
            }
            $revision->forceFill(['source_snapshot' => $snapshot, 'status' => BcmRevisionStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(), 'lock_version' => $revision->lock_version + 1])->save();
            $this->record('approved', $revision, $project, $actor, ['status']);

            return $revision;
        });
    }

    /** @return array{IsmsProject, ContinuityPlanRevision} */
    private function locked(ContinuityPlanRevision $revision, int $expectedLockVersion, User $actor): array
    {
        $project = $this->access->lockedWritable(IsmsProject::query()->findOrFail($revision->project_id), $actor);
        $revision = ContinuityPlanRevision::query()->whereKey($revision->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
        $this->access->assertVersion($revision, $expectedLockVersion);
        if ($revision->status !== BcmRevisionStatus::Draft) {
            throw new ConflictHttpException('Freigegebene Planrevisionen sind unveränderlich.');
        }

        return [$project, $revision];
    }

    /** @param array<string, mixed> $data */
    private function apply(ContinuityPlanRevision $revision, array $data): bool
    {
        $changed = false;
        foreach (['process_ids' => ['processes', 'business_process_id'], 'evidence_ids' => ['evidenceLinks', 'evidence_id'], 'risk_analysis_ids' => ['riskLinks', 'risk_analysis_id'], 'steps' => ['steps', null], 'roles' => ['roles', null]] as $key => [$relation, $column]) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $fields = $column ? [$column] : ($key === 'steps' ? ['instruction', 'owner_name', 'expected_minutes'] : ['role', 'name', 'email', 'phone', 'deputy_name']);
            $rows = [];
            foreach ($data[$key] as $index => $row) {
                if ($column) {
                    $rows[] = [$column => $row];
                } else {
                    $rows[] = ['position' => $index + 1, ...array_replace(array_fill_keys($fields, null), $row)];
                }
            }
            if ($column) {
                usort($rows, fn ($a, $b) => $a[$column] <=> $b[$column]);
                $model = match ($key) {
                    'process_ids' => BusinessProcess::class,
                    'evidence_ids' => EvidenceFile::class,
                    default => RiskAnalysis::class,
                };
                foreach ($rows as $row) {
                    $model::query()->whereKey($row[$column])->where('project_id', $revision->project_id)->lockForUpdate()->firstOrFail();
                }
            }
            if (! $column) {
                array_unshift($fields, 'position');
            }
            $existing = $revision->{$relation}()->orderBy($column ?? 'position')->get()->map(fn ($child) => $child->only($fields))->all();
            if ($rows !== $existing) {
                $revision->{$relation}()->delete();
                foreach ($rows as $row) {
                    $revision->{$relation}()->create([...$row, 'project_id' => $revision->project_id]);
                }
                $changed = true;
            }
            unset($data[$key]);
        }
        $revision->fill($data);

        return $changed;
    }

    /** @param list<string> $fields */
    private function record(string $action, ContinuityPlanRevision $revision, IsmsProject $project, User $actor, array $fields): void
    {
        $this->audit->record('bcm.plan.'.$action, $actor, ['project_id' => $project->id, 'source_id' => $revision->id, 'new_status' => $revision->status->value, 'changed_fields' => $fields], $project->organization_id);
    }
}
