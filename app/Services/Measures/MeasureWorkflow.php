<?php

namespace App\Services\Measures;

use App\Enums\FindingStatus;
use App\Enums\MeasurePriority;
use App\Enums\MeasureStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeasureWorkflow
{
    /** @var array<string, list<MeasureStatus>> */
    private const TRANSITIONS = [
        'planned' => [MeasureStatus::InProgress, MeasureStatus::Cancelled],
        'in_progress' => [MeasureStatus::Blocked, MeasureStatus::Completed, MeasureStatus::Cancelled],
        'blocked' => [MeasureStatus::InProgress, MeasureStatus::Cancelled],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(Finding $finding, array $data, User $actor): Measure
    {
        $validated = $this->validateContent($data);

        return DB::transaction(function () use ($finding, $validated, $actor): Measure {
            $project = $this->lockedWritableProject($finding->project, $actor);
            $lockedFinding = $this->lockedAcceptedFinding($project, $finding);
            $measure = Measure::query()->create([
                ...$validated,
                'project_id' => $project->id,
                'finding_id' => $lockedFinding->id,
                'status' => MeasureStatus::Planned,
                'created_by' => $actor->id,
                'completed_by' => null,
                'completed_at' => null,
                'cancelled_reason' => null,
            ]);
            $this->audit->record('measure.created', $actor, [
                'project_id' => $project->id,
                'finding_id' => $lockedFinding->id,
                'measure_id' => $measure->id,
                'new_status' => MeasureStatus::Planned->value,
            ], $project->organization_id);

            return $measure;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Measure $measure, array $data, User $actor): Measure
    {
        $validated = $this->validateContent($data);

        return DB::transaction(function () use ($measure, $validated, $actor): Measure {
            $project = $this->lockedWritableProject($measure->project, $actor);
            $this->lockedAcceptedFinding($project, $measure->finding);
            $locked = $this->lockedMeasure($project, $measure);
            if (in_array($locked->status, [MeasureStatus::Completed, MeasureStatus::Cancelled], true)) {
                $this->reject('measure', 'Abgeschlossene oder abgebrochene Maßnahmen können nicht bearbeitet werden.');
            }

            $locked->fill($validated);
            $changedFields = array_values(array_intersect(
                ['title', 'description', 'priority', 'responsible_name', 'responsible_email', 'due_date'],
                array_keys($locked->getDirty()),
            ));
            if ($changedFields === []) {
                return $locked;
            }

            $locked->save();
            $this->audit->record('measure.updated', $actor, [
                'project_id' => $project->id,
                'finding_id' => $locked->finding_id,
                'measure_id' => $locked->id,
                'changed_fields' => $changedFields,
            ], $project->organization_id);

            return $locked;
        });
    }

    public function transition(Measure $measure, MeasureStatus $target, ?string $reason, User $actor): Measure
    {
        $validatedReason = $this->validateReason($target, $reason);

        return DB::transaction(function () use ($measure, $target, $validatedReason, $actor): Measure {
            $project = $this->lockedWritableProject($measure->project, $actor);
            $this->lockedAcceptedFinding($project, $measure->finding);
            $locked = $this->lockedMeasure($project, $measure);
            $oldStatus = $locked->status;
            if (! in_array($target, self::TRANSITIONS[$oldStatus->value], true)) {
                $this->reject('status', 'Dieser Statuswechsel ist nicht zulässig.');
            }

            $locked->update([
                'status' => $target,
                'completed_by' => $target === MeasureStatus::Completed ? $actor->id : null,
                'completed_at' => $target === MeasureStatus::Completed ? now() : null,
                'cancelled_reason' => $target === MeasureStatus::Cancelled ? $validatedReason : null,
            ]);
            $this->audit->record('measure.status_changed', $actor, [
                'project_id' => $project->id,
                'finding_id' => $locked->finding_id,
                'measure_id' => $locked->id,
                'old_status' => $oldStatus->value,
                'new_status' => $target->value,
            ], $project->organization_id);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, description: string, priority: string, responsible_name: string, responsible_email: string|null, due_date: string}
     */
    private function validateContent(array $data): array
    {
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['required', Rule::enum(MeasurePriority::class)],
            'responsible_name' => ['required', 'string', 'max:255'],
            'responsible_email' => ['nullable', 'email', 'max:255'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        $validated['responsible_email'] = $validated['responsible_email'] ?? null;

        /** @var array{title: string, description: string, priority: string, responsible_name: string, responsible_email: string|null, due_date: string} $validated */
        return $validated;
    }

    private function validateReason(MeasureStatus $target, ?string $reason): ?string
    {
        $validated = Validator::make(
            ['reason' => $reason],
            ['reason' => [$target === MeasureStatus::Cancelled ? 'required' : 'nullable', 'string', 'max:10000']],
        )->validate();
        $validatedReason = $validated['reason'] ?? null;
        assert($validatedReason === null || is_string($validatedReason));

        return $validatedReason;
    }

    private function lockedWritableProject(IsmsProject $project, User $actor): IsmsProject
    {
        $actor->loadMissing('organization');
        if (! $actor->is_active
            || $actor->organization?->organization_type !== 'internal'
            || ! in_array($actor->role, [UserRole::Admin, UserRole::Consultant], true)) {
            $this->reject('measure', 'Die Aktion ist nicht zulässig.');
        }

        $locked = IsmsProject::query()->with('organization')->whereKey($project->id)->lockForUpdate()->first();
        if (! $locked instanceof IsmsProject
            || $locked->organization?->organization_type !== 'customer'
            || ! $locked->organization->is_active
            || ! in_array($locked->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            $this->reject('measure', 'Das Projekt ist nicht beschreibbar.');
        }

        return $locked;
    }

    private function lockedAcceptedFinding(IsmsProject $project, Finding $finding): Finding
    {
        $locked = Finding::query()
            ->whereKey($finding->id)
            ->where('project_id', $project->id)
            ->lockForUpdate()
            ->first();
        if (! $locked instanceof Finding || $locked->status !== FindingStatus::Accepted) {
            $this->reject('finding', 'Nur akzeptierte Feststellungen können Maßnahmen erhalten.');
        }

        return $locked;
    }

    private function lockedMeasure(IsmsProject $project, Measure $measure): Measure
    {
        $locked = Measure::query()
            ->whereKey($measure->id)
            ->where('project_id', $project->id)
            ->lockForUpdate()
            ->first();
        if (! $locked instanceof Measure) {
            $this->reject('measure', 'Die Maßnahme gehört nicht zu diesem Projekt.');
        }

        return $locked;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
