<?php

namespace Tests\Feature\Bcm;

use App\Models\BiaConfigurationVersion;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;

trait InteractsWithBcm
{
    protected function completeBiaData(BiaConfigurationVersion $configuration): array
    {
        $impacts = [];
        foreach (['financial', 'operational', 'legal', 'reputation'] as $area) {
            foreach ($configuration->minutes as $minute) {
                $impacts[] = ['area' => $area, 'minutes' => $minute, 'level' => 2, 'reason' => 'Reviewed impact'];
            }
        }

        return ['owner_name' => 'Process owner', 'mtpd_minutes' => 600, 'rto_minutes' => 120,
            'rpo_minutes' => 0, 'rpo_not_applicable' => false, 'rpo_reason' => null,
            'target_reason' => 'Restore before maximum outage', 'impacts' => $impacts];
    }

    /** @return array{IsmsProject, User} */
    protected function bcmProject(bool $withBcms = false): array
    {
        return [
            IsmsProject::factory()->create(['bcm_level' => $withBcms ? 'aufbau_bcms' : 'kein_bcms']),
            User::factory()->for(Organization::factory()->create(['organization_type' => 'internal', 'is_active' => true]))->create(['is_active' => true]),
        ];
    }
}
