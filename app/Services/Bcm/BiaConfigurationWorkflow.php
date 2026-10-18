<?php

namespace App\Services\Bcm;

use App\Enums\BcmRevisionStatus;
use App\Models\BiaConfigurationVersion;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BiaConfigurationWorkflow
{
    public function __construct(private readonly BcmProjectAccess $access, private readonly AuditLogger $audit) {}

    public function initialize(IsmsProject $project, User $actor): BiaConfigurationVersion
    {
        return DB::transaction(function () use ($project, $actor) {
            $project = $this->access->lockedWritable($project, $actor);

            return $this->current($project) ?? $this->publish($project, [60, 240, 480, 1440, 4320, 10080], $actor);
        });
    }

    /** @param array<mixed> $minutes */
    public function activate(IsmsProject $project, array $minutes, int $expectedLockVersion, User $actor): BiaConfigurationVersion
    {
        return DB::transaction(function () use ($project, $minutes, $expectedLockVersion, $actor) {
            $project = $this->access->lockedWritable($project, $actor);
            $this->validateMinutes($minutes);
            if ($expectedLockVersion < 1 || $project->bia_configuration_lock_version !== $expectedLockVersion) {
                throw new ConflictHttpException('Die Konfiguration wurde geändert. Bitte laden Sie sie neu.');
            }

            return $this->publish($project, $minutes, $actor);
        });
    }

    public function current(IsmsProject $project): ?BiaConfigurationVersion
    {
        $id = IsmsProject::query()->whereKey($project->id)->value('bia_configuration_version_id');

        return $id ? BiaConfigurationVersion::query()->whereKey($id)->where('project_id', $project->id)->first() : null;
    }

    /** @param array<mixed> $minutes */
    private function validateMinutes(array $minutes): void
    {
        $previous = 0;
        $valid = array_is_list($minutes) && count($minutes) >= 2 && count($minutes) <= 12;
        foreach ($minutes as $minute) {
            if (! is_int($minute) || $minute <= $previous || $minute > 525600) {
                $valid = false;
                break;
            }
            $previous = $minute;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['minutes' => 'Bitte geben Sie 2 bis 12 strikt steigende positive ganze Minutenwerte bis 525600 an.']);
        }
    }

    /** @param array<mixed> $minutes */
    private function publish(IsmsProject $project, array $minutes, User $actor): BiaConfigurationVersion
    {
        $generation = $project->bia_configuration_lock_version + 1;
        $configuration = BiaConfigurationVersion::query()->create([
            'project_id' => $project->id, 'version' => $generation, 'lock_version' => 1,
            'minutes' => $minutes, 'status' => BcmRevisionStatus::Approved,
            'created_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(),
        ]);
        $project->forceFill(['bia_configuration_version_id' => $configuration->id, 'bia_configuration_lock_version' => $generation])->save();
        $this->audit->record('bcm.configuration.activated', $actor, [
            'project_id' => $project->id, 'source_id' => $configuration->id,
            'new_status' => BcmRevisionStatus::Approved->value, 'changed_fields' => ['minutes'],
        ], $project->organization_id);

        return $configuration;
    }
}
