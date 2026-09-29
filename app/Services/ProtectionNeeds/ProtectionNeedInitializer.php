<?php

namespace App\Services\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Enums\ProtectionObjective;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProtectionNeedInitializer
{
    public function __construct(private readonly ProtectionNeedAccess $access) {}

    /** @return Collection<int, ProtectionNeed> */
    public function ensureTarget(DependencyNode $target): Collection
    {
        return DB::transaction(function () use ($target): Collection {
            $project = $this->access->project(IsmsProject::query()->findOrFail($target->projectId));
            $this->access->target($project, $target, false);
            foreach (ProtectionObjective::cases() as $objective) {
                ProtectionNeed::query()->firstOrCreate([
                    'project_id' => $project->id,
                    'business_process_id' => $target->type === DependencyNodeType::Process ? $target->id : null,
                    'asset_id' => $target->type === DependencyNodeType::Asset ? $target->id : null,
                    'objective' => $objective->value,
                ], ['status' => 'draft']);
            }

            return ProtectionNeed::query()->where('project_id', $project->id)
                ->where($target->type === DependencyNodeType::Process ? 'business_process_id' : 'asset_id', $target->id)->get();
        });
    }
}
