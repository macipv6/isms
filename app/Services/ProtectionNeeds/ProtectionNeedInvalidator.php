<?php

namespace App\Services\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Models\ProtectionNeed;

class ProtectionNeedInvalidator
{
    public function invalidate(ProtectionNeed $need): void
    {
        $need->downgradeRequests()->whereIn('status', ['pending', 'approved'])->update(['status' => 'invalidated']);
        $need->update(['needs_review' => true, 'lock_version' => $need->lock_version + 1]);
    }

    public function invalidateTarget(DependencyNode $target): void
    {
        $column = $target->type === DependencyNodeType::Process ? 'business_process_id' : 'asset_id';
        foreach (ProtectionNeed::query()->where('project_id', $target->projectId)->where($column, $target->id)->get() as $need) {
            $this->invalidate($need);
        }
    }
}
