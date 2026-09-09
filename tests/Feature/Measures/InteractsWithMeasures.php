<?php

namespace Tests\Feature\Measures;

use App\Enums\FindingStatus;
use App\Enums\UserRole;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;

trait InteractsWithMeasures
{
    /** @return array{Organization, IsmsProject, Finding, User} */
    protected function measureContext(array $projectAttributes = []): array
    {
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
        ]);
        $project = IsmsProject::factory()->for($customer)->create($projectAttributes);
        $actor = $this->internalMeasureUser();
        $finding = Finding::factory()->for($project)->create([
            'status' => FindingStatus::Accepted,
            'proposed_by' => $actor->id,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);

        return [$customer, $project, $finding, $actor];
    }

    protected function internalMeasureUser(UserRole $role = UserRole::Consultant, bool $active = true): User
    {
        return User::factory()
            ->for(Organization::factory()->create(['organization_type' => 'internal']))
            ->create(['role' => $role, 'is_active' => $active]);
    }

    /** @return array<string, mixed> */
    protected function measurePayload(array $overrides = []): array
    {
        return [
            'title' => 'Sicherheitsziele vervollständigen',
            'description' => 'Die fehlenden Sicherheitsziele werden dokumentiert und freigegeben.',
            'priority' => 'high',
            'responsible_name' => 'Alex Beispiel',
            'responsible_email' => 'alex@example.test',
            'due_date' => '2025-01-31',
            ...$overrides,
        ];
    }

    protected function measureStoreUrl(Organization $organization, IsmsProject $project, Finding $finding): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/findings/'.$finding->id.'/measures';
    }

    protected function measureUrl(Organization $organization, IsmsProject $project, object $measure, string $suffix = ''): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/measures/'.$measure->id.$suffix;
    }
}
