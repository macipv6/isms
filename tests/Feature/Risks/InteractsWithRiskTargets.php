<?php

namespace Tests\Feature\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Threats\ProjectRiskContextService;
use Database\Seeders\RiskMethodologySeeder;
use Database\Seeders\ThreatCatalogSeeder;

trait InteractsWithRiskTargets
{
    protected function riskTarget(): array
    {
        $this->seed([RiskMethodologySeeder::class, ThreatCatalogSeeder::class]);
        $project = IsmsProject::factory()->for(Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $target = DependencyNode::process($process);
        $context = app(ProjectRiskContextService::class)->initialize($project, RiskMethodologyVersion::query()->sole(), ThreatCatalogVersion::query()->sole(), $actor);

        return [$project, $target, $actor, $context];
    }
}
