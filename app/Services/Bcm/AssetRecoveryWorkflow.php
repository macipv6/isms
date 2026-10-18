<?php

namespace App\Services\Bcm;

use App\Enums\BcmRevisionStatus;
use App\Models\Asset;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AssetRecoveryWorkflow
{
    public function __construct(private readonly BcmProjectAccess $access, private readonly BiaReleaseValidator $validator, private readonly AuditLogger $audit) {}

    public function draft(Asset $asset, User $actor): AssetRecoveryObjectiveRevision
    {
        return DB::transaction(function () use ($asset, $actor) {
            $project = $this->access->lockedWritable(IsmsProject::query()->findOrFail($asset->project_id), $actor);
            $asset = Asset::query()->whereKey($asset->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            $this->active($asset);
            $query = AssetRecoveryObjectiveRevision::query()->where('asset_id', $asset->id)->where('project_id', $project->id);
            $draft = (clone $query)->where('status', BcmRevisionStatus::Draft)->lockForUpdate()->first();
            if ($draft) {
                return $draft;
            }
            $previous = (clone $query)->where('status', BcmRevisionStatus::Approved)->orderByDesc('version')->lockForUpdate()->first();
            $data = $previous?->only(['rto_minutes', 'rpo_minutes', 'rpo_not_applicable', 'rpo_reason', 'target_reason']) ?? [];
            $revision = AssetRecoveryObjectiveRevision::query()->create(array_merge($data, [
                'project_id' => $project->id, 'asset_id' => $asset->id, 'version' => ((int) $query->max('version')) + 1,
                'lock_version' => 1, 'status' => BcmRevisionStatus::Draft, 'created_by' => $actor->id,
                'source_snapshot' => ['asset_id' => $asset->id],
            ]));
            $this->record('drafted', $revision, $project, $actor, []);

            return $revision;
        });
    }

    /** @param array<string, mixed> $data */
    public function save(AssetRecoveryObjectiveRevision $revision, array $data, int $expectedLockVersion, User $actor): AssetRecoveryObjectiveRevision
    {
        return DB::transaction(function () use ($revision, $data, $expectedLockVersion, $actor) {
            [$project, $revision] = $this->locked($revision, $expectedLockVersion, $actor);
            $data = $this->validator->draftData($data);
            $revision->fill($data);
            if ($revision->rpo_not_applicable) {
                $revision->rpo_minutes = null;
            }
            if ($revision->isDirty()) {
                $revision->lock_version++;
                $revision->save();
                $this->record('saved', $revision, $project, $actor, array_keys($data));
            }

            return $revision;
        });
    }

    public function approve(AssetRecoveryObjectiveRevision $revision, int $expectedLockVersion, User $actor): AssetRecoveryObjectiveRevision
    {
        return DB::transaction(function () use ($revision, $expectedLockVersion, $actor) {
            [$project, $revision] = $this->locked($revision, $expectedLockVersion, $actor);
            $this->validator->validateRecovery($revision);
            $revision->forceFill(['source_snapshot' => ['asset_id' => $revision->asset_id, 'revision_id' => $revision->id, 'version' => $revision->version], 'status' => BcmRevisionStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(), 'lock_version' => $revision->lock_version + 1])->save();
            $this->record('approved', $revision, $project, $actor, ['status']);

            return $revision;
        });
    }

    /** @return array{IsmsProject, AssetRecoveryObjectiveRevision} */
    private function locked(AssetRecoveryObjectiveRevision $revision, int $expectedLockVersion, User $actor): array
    {
        $project = $this->access->lockedWritable(IsmsProject::query()->findOrFail($revision->project_id), $actor);
        $revision = AssetRecoveryObjectiveRevision::query()->whereKey($revision->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
        $this->access->assertVersion($revision, $expectedLockVersion);
        if ($revision->status !== BcmRevisionStatus::Draft) {
            throw new ConflictHttpException('Freigegebene Revisionen sind unveränderlich.');
        }
        $asset = Asset::query()->whereKey($revision->asset_id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
        $this->active($asset);

        return [$project, $revision];
    }

    private function active(Asset $asset): void
    {
        if (! $asset->is_active) {
            throw ValidationException::withMessages(['asset_id' => 'Das Asset ist nicht aktiv.']);
        }
    }

    /** @param list<string> $fields */
    private function record(string $action, AssetRecoveryObjectiveRevision $revision, IsmsProject $project, User $actor, array $fields): void
    {
        $this->audit->record('bcm.asset_recovery.'.$action, $actor, ['project_id' => $project->id, 'source_id' => $revision->id, 'new_status' => $revision->status->value, 'changed_fields' => $fields], $project->organization_id);
    }
}
