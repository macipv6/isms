<?php

namespace App\Services\Bcm;

use App\Enums\BcmRevisionStatus;
use App\Models\BiaRevision;
use App\Models\BusinessImpactAnalysis;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BiaWorkflow
{
    public function __construct(private readonly BcmProjectAccess $access, private readonly BiaConfigurationWorkflow $configuration, private readonly BiaReleaseValidator $validator, private readonly AuditLogger $audit) {}

    public function draft(BusinessProcess $process, User $actor): BiaRevision
    {
        return DB::transaction(function () use ($process, $actor) {
            $project = $this->access->lockedWritable(IsmsProject::query()->findOrFail($process->project_id), $actor);
            $process = BusinessProcess::query()->whereKey($process->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            $this->active($process);
            $configuration = $this->configuration->current($project);
            if (! $configuration) {
                throw ValidationException::withMessages(['bia_configuration_version_id' => 'Bitte initialisieren Sie zuerst die BIA-Konfiguration.']);
            }
            $analysis = BusinessImpactAnalysis::query()->firstOrCreate(['project_id' => $project->id, 'business_process_id' => $process->id]);
            $draft = $analysis->revisions()->where('status', BcmRevisionStatus::Draft)->lockForUpdate()->first();
            if ($draft) {
                if ($draft->bia_configuration_version_id !== $configuration->id) {
                    $draft->impacts()->whereNotIn('minutes', $configuration->minutes)->delete();
                    $draft->forceFill([
                        'bia_configuration_version_id' => $configuration->id,
                        'source_snapshot' => ['bia_configuration_version_id' => $configuration->id],
                        'lock_version' => $draft->lock_version + 1,
                    ])->save();
                    $this->record('rebased', $draft, $project, $actor, ['bia_configuration_version_id', 'impacts', 'source_snapshot']);
                }

                return $draft;
            }
            $previous = $analysis->revisions()->where('status', BcmRevisionStatus::Approved)->orderByDesc('version')->lockForUpdate()->first();
            $data = $previous?->only(['owner_name', 'mtpd_minutes', 'rto_minutes', 'rpo_minutes', 'rpo_not_applicable', 'rpo_reason', 'target_reason']) ?? [];
            $revision = $analysis->revisions()->create(array_merge($data, [
                'project_id' => $project->id, 'bia_configuration_version_id' => $configuration->id,
                'version' => ((int) $analysis->revisions()->max('version')) + 1,
                'lock_version' => 1, 'status' => BcmRevisionStatus::Draft, 'created_by' => $actor->id,
                'source_snapshot' => ['bia_configuration_version_id' => $configuration->id],
            ]));
            if ($previous) {
                foreach ($previous->impacts()->whereIn('minutes', $configuration->minutes)->get() as $impact) {
                    $revision->impacts()->create(array_merge($impact->only(['area', 'minutes', 'level', 'reason']), ['project_id' => $project->id]));
                }
            }
            $this->record('drafted', $revision, $project, $actor, []);

            return $revision;
        });
    }

    /** @param array<string, mixed> $data */
    public function save(BiaRevision $revision, array $data, int $expectedLockVersion, User $actor): BiaRevision
    {
        return DB::transaction(function () use ($revision, $data, $expectedLockVersion, $actor) {
            [$project, $revision] = $this->locked($revision, $expectedLockVersion, $actor);
            $data = $this->validator->draftData($data, true);
            $fields = array_keys($data);
            $impactsChanged = false;
            if (array_key_exists('impacts', $data)) {
                $seen = [];
                foreach ($data['impacts'] as $impact) {
                    $coordinate = $impact['area'].':'.$impact['minutes'];
                    if (isset($seen[$coordinate]) || ! in_array($impact['minutes'], $revision->configuration->minutes, true)) {
                        throw ValidationException::withMessages(['impacts' => 'Zeitstufen müssen zur Konfiguration passen und je Bereich eindeutig sein.']);
                    }
                    $seen[$coordinate] = true;
                }
                $incoming = $this->normalizedImpacts($data['impacts']);
                $existing = $this->normalizedImpacts($revision->impacts()->get()->map(fn ($impact) => [
                    'area' => $impact->area->value, 'minutes' => $impact->minutes,
                    'level' => $impact->level, 'reason' => $impact->reason,
                ])->all());
                $impactsChanged = $incoming !== $existing;
                if ($impactsChanged) {
                    $revision->impacts()->delete();
                    foreach ($incoming as $impact) {
                        $revision->impacts()->create(array_merge($impact, ['project_id' => $project->id]));
                    }
                }
                unset($data['impacts']);
            }
            $revision->fill($data);
            if ($revision->rpo_not_applicable) {
                $revision->rpo_minutes = null;
            }
            if ($revision->isDirty() || $impactsChanged) {
                $revision->lock_version++;
                $revision->save();
                $this->record('saved', $revision, $project, $actor, $fields);
            }

            return $revision;
        });
    }

    public function approve(BiaRevision $revision, int $expectedLockVersion, User $actor): BiaRevision
    {
        return DB::transaction(function () use ($revision, $expectedLockVersion, $actor) {
            [$project, $revision] = $this->locked($revision, $expectedLockVersion, $actor);
            $this->validator->validate($revision);
            $revision->forceFill(['status' => BcmRevisionStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(), 'lock_version' => $revision->lock_version + 1])->save();
            $this->record('approved', $revision, $project, $actor, ['status']);

            return $revision;
        });
    }

    /** @return array{IsmsProject, BiaRevision} */
    private function locked(BiaRevision $revision, int $expectedLockVersion, User $actor): array
    {
        $project = $this->access->lockedWritable(IsmsProject::query()->findOrFail($revision->project_id), $actor);
        $revision = BiaRevision::query()->whereKey($revision->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
        $this->access->assertVersion($revision, $expectedLockVersion);
        if ($revision->status !== BcmRevisionStatus::Draft) {
            throw new ConflictHttpException('Freigegebene Revisionen sind unveränderlich.');
        }
        $process = BusinessProcess::query()->whereKey($revision->analysis->business_process_id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
        $this->active($process);

        return [$project, $revision];
    }

    private function active(BusinessProcess $process): void
    {
        if (! $process->is_active) {
            throw ValidationException::withMessages(['business_process_id' => 'Der Prozess ist nicht aktiv.']);
        }
    }

    /** @param list<array<string, mixed>> $impacts
     * @return array<string, array<string, mixed>>
     */
    private function normalizedImpacts(array $impacts): array
    {
        $normalized = [];
        foreach ($impacts as $impact) {
            $normalized[$impact['area'].':'.$impact['minutes']] = [
                'area' => $impact['area'], 'minutes' => $impact['minutes'],
                'level' => $impact['level'] ?? null, 'reason' => $impact['reason'] ?? null,
            ];
        }
        ksort($normalized);

        return $normalized;
    }

    /** @param list<string> $fields */
    private function record(string $action, BiaRevision $revision, IsmsProject $project, User $actor, array $fields): void
    {
        $this->audit->record('bcm.bia.'.$action, $actor, ['project_id' => $project->id, 'source_id' => $revision->id, 'new_status' => $revision->status->value, 'changed_fields' => $fields], $project->organization_id);
    }
}
