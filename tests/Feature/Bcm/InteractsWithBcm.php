<?php

namespace Tests\Feature\Bcm;

use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;

trait InteractsWithBcm
{
    /** @return array{IsmsProject, User} */
    protected function bcmProject(bool $withBcms = false): array
    {
        return [
            IsmsProject::factory()->create(['bcm_level' => $withBcms ? 'aufbau_bcms' : 'kein_bcms']),
            User::factory()->for(Organization::factory()->create(['organization_type' => 'internal', 'is_active' => true]))->create(['is_active' => true]),
        ];
    }
}
