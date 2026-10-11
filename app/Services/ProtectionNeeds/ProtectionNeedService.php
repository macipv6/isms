<?php

namespace App\Services\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Enums\ProtectionLevel;
use App\Enums\ProtectionNeedStatus;
use App\Enums\ProtectionObjective;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProtectionNeedService
{
    public function __construct(
        private readonly ProtectionNeedAccess $access,
        private readonly ProtectionNeedInitializer $initializer,
        private readonly ProtectionNeedInvalidator $invalidator,
        private readonly ProtectionNeedPropagator $propagator,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $ratings
     * @param  array<string, int>  $expectedVersions
     * @return Collection<int, ProtectionNeed>
     */
    public function rateTarget(IsmsProject $project, DependencyNode $target, array $ratings, User $actor, array $expectedVersions): Collection
    {
        return DB::transaction(function () use ($project, $target, $ratings, $actor, $expectedVersions): Collection {
            $actor = $this->access->writer($actor);
            $project = $this->access->project($project);
            $this->access->target($project, $target);
            $rows = $this->initializer->ensureTarget($target)->keyBy(fn (ProtectionNeed $need): string => $need->objective->value);
            $this->assertVersions($rows, $expectedVersions);
            if ($ratings === []) {
                $this->reject('ratings', 'Mindestens eine Bewertung ist erforderlich.');
            }
            $wasApproved = $rows->contains(fn (ProtectionNeed $need): bool => $need->status === ProtectionNeedStatus::Approved);
            $changedIds = [];
            foreach ($ratings as $objective => $rating) {
                if (! ProtectionObjective::tryFrom($objective) || ! is_array($rating) || ! isset($rating['level']) || ! is_string($rating['level']) || ! ProtectionLevel::tryFrom($rating['level']) || ! is_string($rating['rationale'] ?? null) || trim($rating['rationale']) === '') {
                    $this->reject('ratings', 'Für jeden direkten Schutzbedarf sind Wert und Begründung erforderlich.');
                }
                $need = $rows[$objective];
                $level = ProtectionLevel::from($rating['level']);
                if ($need->direct_level === $level && $need->direct_rationale === trim($rating['rationale'])) {
                    continue;
                }
                $invalidated = $this->invalidator->invalidate($need, $actor);
                $changedIds[$need->id] = true;
                $need->refresh();
                $need->fill(['direct_level' => $level, 'direct_rationale' => trim($rating['rationale']), 'rated_by' => $actor->id, 'rated_at' => now(), 'status' => ProtectionNeedStatus::Draft, 'lock_version' => $need->lock_version + ($invalidated ? 0 : 1)]);
                $need->save();
                $this->audit->record('protection_need.rated', $actor, ['project_id' => $project->id, 'protection_need_id' => $need->id, 'objective' => $objective, 'new_level' => $level->value], $project->organization_id);
            }
            if ($wasApproved && $changedIds !== []) {
                foreach ($rows as $need) {
                    if (! isset($changedIds[$need->id])) {
                        $this->invalidator->invalidate($need, $actor);
                    }
                }
            }
            $complete = $rows->every(fn (ProtectionNeed $need): bool => $need->fresh()->direct_level !== null && trim($need->fresh()->direct_rationale ?? '') !== '');
            foreach ($rows as $need) {
                $need->refresh();
                if ($changedIds !== [] || $need->status !== ProtectionNeedStatus::Approved) {
                    $need->status = $complete ? ProtectionNeedStatus::Assessed : ProtectionNeedStatus::Draft;
                    $need->save();
                }
            }
            $this->propagator->recalculate($project, [$target], $actor);

            return $this->initializer->ensureTarget($target);
        });
    }

    /** @param array<string, int> $expectedVersions */
    public function approveTarget(IsmsProject $project, DependencyNode $target, User $actor, array $expectedVersions): void
    {
        DB::transaction(function () use ($project, $target, $actor, $expectedVersions): void {
            $actor = $this->access->writer($actor);
            $project = $this->access->project($project);
            $this->access->target($project, $target);
            $rows = $this->initializer->ensureTarget($target)->keyBy(fn (ProtectionNeed $need): string => $need->objective->value);
            $this->assertVersions($rows, $expectedVersions);
            foreach ($rows as $need) {
                if ($need->status === ProtectionNeedStatus::Draft) {
                    $this->reject('status', 'Der Schutzbedarf muss zuerst vollständig bewertet werden.');
                }
                if (! $need->direct_level || trim($need->direct_rationale ?? '') === '') {
                    $this->reject('ratings', 'Alle drei Schutzziele müssen bewertet und begründet sein.');
                }
                if ($need->inherited_level && $need->inherited_level->rank() > $need->direct_level->rank() && ! $need->downgradeRequests()->where('status', 'approved')->where('inherited_level', $need->inherited_level->value)->where('requested_level', $need->direct_level->value)->exists()) {
                    $this->reject('downgrade', 'Die Herabstufung benötigt eine gültige zweite Entscheidung.');
                }
            }
            foreach ($rows as $need) {
                $need->update(['status' => ProtectionNeedStatus::Approved, 'needs_review' => false, 'lock_version' => $need->lock_version + 1]);
            }
            $this->audit->record('protection_need.approved', $actor, ['project_id' => $project->id, 'target_type' => $target->type->value, 'target_id' => $target->id, 'new_status' => 'approved'], $project->organization_id);
        });
    }

    /** @param Collection<string, ProtectionNeed> $rows
     * @param  array<string, int>  $versions
     */
    private function assertVersions(Collection $rows, array $versions): void
    {
        foreach ($rows as $need) {
            if (! array_key_exists($need->objective->value, $versions) || $versions[$need->objective->value] !== $need->lock_version) {
                $this->reject('lock_version', 'Der Schutzbedarf wurde zwischenzeitlich geändert.');
            }
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
