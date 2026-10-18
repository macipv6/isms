<?php

namespace App\Services\Bcm;

use App\Enums\BcmRevisionStatus;
use App\Models\BcmConflictAcceptance;
use App\Models\BiaRevision;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BcmConflictWorkflow
{
    public function __construct(private readonly BcmProjectAccess $access, private readonly BcmSourceSnapshot $sources, private readonly BcmConsistencyChecker $checker, private readonly AuditLogger $audit) {}

    public function accept(BiaRevision $revision, string $fingerprint, string $reason, int $expectedLockVersion, User $actor): BcmConflictAcceptance
    {
        return DB::transaction(function () use ($revision, $fingerprint, $reason, $expectedLockVersion, $actor) {
            $project = $this->access->lockedWritable(IsmsProject::query()->findOrFail($revision->project_id), $actor);
            $revision = BiaRevision::query()->whereKey($revision->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            $this->access->assertVersion($revision, $expectedLockVersion);
            if ($revision->status !== BcmRevisionStatus::Draft) {
                throw new ConflictHttpException('Freigegebene Revisionen sind unveränderlich.');
            }
            $this->sources->lockProjectSources($project);
            if (! $revision->analysis->process->is_active) {
                throw ValidationException::withMessages(['business_process_id' => 'Der Prozess ist nicht aktiv.']);
            }
            if ($project->bia_configuration_version_id !== $revision->bia_configuration_version_id) {
                throw new ConflictHttpException('Die BIA-Konfiguration wurde geändert.');
            }
            Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:10000']])->validate();
            $warning = null;
            foreach ($this->checker->warnings($revision) as $candidate) {
                if (hash_equals($candidate->fingerprint, $fingerprint)) {
                    $warning = $candidate;
                    break;
                }
            }
            if (! $warning) {
                throw new ConflictHttpException('Die Warnung oder ihre Quellen wurden geändert. Bitte prüfen Sie erneut.');
            }
            $existing = BcmConflictAcceptance::query()->where('project_id', $project->id)->where('bia_revision_id', $revision->id)->where('fingerprint', $fingerprint)->first();
            if ($existing) {
                return $existing;
            }
            $acceptance = BcmConflictAcceptance::query()->create([
                'project_id' => $project->id, 'bia_revision_id' => $revision->id, 'fingerprint' => $fingerprint,
                'warning_code' => $warning->code, 'reason' => trim($reason), 'accepted_by' => $actor->id, 'accepted_at' => now(),
                'warning_snapshot' => ['code' => $warning->code, 'target_type' => $warning->targetType->value,
                    'target_id' => $warning->targetId, 'revision_id' => $warning->revisionId,
                    'path_edge_ids' => $warning->pathEdgeIds, 'blocking' => $warning->blocking, 'fingerprint' => $warning->fingerprint,
                    'sources' => $this->sources->bia($revision)],
            ]);
            $revision->forceFill(['lock_version' => $revision->lock_version + 1])->save();
            $this->audit->record('bcm.conflict.accepted', $actor, ['project_id' => $project->id, 'source_id' => $revision->id,
                'decision_id' => $acceptance->id, 'changed_fields' => ['conflict_acceptance']], $project->organization_id);

            return $acceptance;
        });
    }
}
