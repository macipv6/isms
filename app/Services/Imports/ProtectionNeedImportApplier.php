<?php

namespace App\Services\Imports;

use App\Data\Dependencies\DependencyNode;
use App\Enums\ProtectionLevel;
use App\Enums\ProtectionNeedStatus;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
use App\Models\User;
use App\Services\ProtectionNeeds\ProtectionNeedInitializer;
use App\Services\ProtectionNeeds\ProtectionNeedInvalidator;
use App\Services\ProtectionNeeds\ProtectionNeedPropagator;
use Illuminate\Validation\ValidationException;

class ProtectionNeedImportApplier
{
    public function __construct(
        private readonly ProtectionNeedInitializer $initializer,
        private readonly ProtectionNeedInvalidator $invalidator,
        private readonly ProtectionNeedPropagator $propagator,
    ) {}

    /** @param list<array<string, string|bool|null>> $payload */
    public function apply(IsmsProject $project, array $payload, User $actor): void
    {
        $affected = [];
        foreach ($payload as $row) {
            $model = $row['object_type'] === 'process' ? BusinessProcess::class : Asset::class;
            $target = $model::query()->where('project_id', $project->id)->where('key', $row['object_key'])->where('is_active', true)->lockForUpdate()->first();
            if (! $target) {
                throw ValidationException::withMessages(['import' => 'Der Import kann nicht bestätigt werden.']);
            }
            $node = $target instanceof BusinessProcess ? DependencyNode::process($target) : DependencyNode::asset($target);
            $needs = $this->initializer->ensureTarget($node)->keyBy(fn (ProtectionNeed $need): string => $need->objective->value);
            $changed = [];
            $wasApproved = $needs->contains(fn (ProtectionNeed $need): bool => $need->status === ProtectionNeedStatus::Approved);
            foreach (['confidentiality', 'integrity', 'availability'] as $objective) {
                $need = $needs[$objective];
                $level = ProtectionLevel::from($row[$objective]);
                $rationale = $row[$objective.'_rationale'];
                if ($need->direct_level === $level && $need->direct_rationale === $rationale) {
                    continue;
                }
                $invalidated = $this->invalidator->invalidate($need, $actor, false);
                $need->refresh();
                $need->fill([
                    'direct_level' => $level,
                    'direct_rationale' => $rationale,
                    'rated_by' => $actor->id,
                    'rated_at' => now(),
                    'status' => ProtectionNeedStatus::Draft,
                    'lock_version' => $need->lock_version + ($invalidated ? 0 : 1),
                ])->save();
                $changed[$need->id] = true;
            }
            if ($changed === []) {
                continue;
            }
            if ($wasApproved) {
                foreach ($needs as $need) {
                    if (! isset($changed[$need->id])) {
                        $this->invalidator->invalidate($need, $actor, false);
                    }
                }
            }
            foreach ($needs as $need) {
                $need->refresh();
                $need->status = ProtectionNeedStatus::Assessed;
                $need->save();
            }
            $affected[$node->identity()] = $node;
        }
        if ($affected !== []) {
            $this->propagator->recalculate($project, array_values($affected), $actor, false);
        }
    }
}
